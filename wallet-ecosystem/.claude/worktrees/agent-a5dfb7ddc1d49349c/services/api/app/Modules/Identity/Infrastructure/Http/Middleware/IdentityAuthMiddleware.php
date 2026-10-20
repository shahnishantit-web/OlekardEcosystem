<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Middleware;

use App\Modules\Identity\Domain\Contracts\JwksProviderContract;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\Services\SessionService;
use Closure;
use Illuminate\Http\Request;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\Clock\SystemClock;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates Bearer JWT, checks session revocation, and attaches identity claims to the request.
 * Downstream controllers read claims from $request->attributes: identity.user_id, identity.session_id,
 * identity.actor_type, identity.device_id, identity.correlation_id.
 */
final class IdentityAuthMiddleware
{
    public function __construct(
        private readonly JwksProviderContract $jwks,
        private readonly SessionService $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return $this->unauthorized($request, 'Bearer token required.');
        }

        try {
            $claims = $this->validateJwt($token);
        } catch (\Throwable) {
            return $this->unauthorized($request, 'Token is invalid or expired.');
        }

        try {
            $this->sessions->validateSession($claims['session_id']);
        } catch (InvalidCredentialsException) {
            return $this->unauthorized($request, 'Session has been revoked or expired.');
        }

        $request->attributes->set('identity.user_id',    $claims['sub']);
        $request->attributes->set('identity.session_id', $claims['session_id']);
        $request->attributes->set('identity.actor_type', $claims['actor_type'] ?? 'customer');
        $request->attributes->set('identity.device_id',  $claims['device_id'] ?? '');

        return $next($request);
    }

    /**
     * Parse and verify the JWT; return claims array on success.
     */
    private function validateJwt(string $rawJwt): array
    {
        $signingKey  = $this->jwks->getSigningKey();
        $privDetails = openssl_pkey_get_details(openssl_pkey_get_private($signingKey->privateKeyPem));

        if ($privDetails === false) {
            throw new \RuntimeException('Cannot extract public key for JWT verification.');
        }

        $publicKeyPem = $privDetails['key'];

        $config = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($signingKey->privateKeyPem),
            InMemory::plainText($publicKeyPem),
        );

        $parsed = $config->parser()->parse($rawJwt);

        $config->validator()->assert($parsed,
            new LooseValidAt(new SystemClock(new \DateTimeZone('UTC'))),
            new PermittedFor((string) config('app.url', 'identity-service')),
        );

        return array_map(
            static fn ($claim) => $claim,
            $parsed->claims()->all(),
        );
    }

    private function unauthorized(Request $request, string $message): Response
    {
        $correlationId = $request->header('X-Correlation-ID', '');

        return response()->json([
            'success' => false,
            'error'   => [
                'code'     => 'IDENTITY_022',
                'message'  => $message,
                'trace_id' => $correlationId,
            ],
        ], 401, ['X-Correlation-ID' => $correlationId]);
    }
}
