<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Middleware;

use App\Modules\Identity\Domain\Contracts\JwksProviderContract;
use App\Modules\Identity\Domain\Services\SessionService;
use Closure;
use Illuminate\Http\Request;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\Clock\SystemClock;
use Symfony\Component\HttpFoundation\Response;

/**
 * Soft variant of IdentityAuthMiddleware: if a valid Bearer JWT is present,
 * attach identity claims to the request. If absent or invalid, pass through
 * without rejecting. Routes that also accept unauthenticated tokens (e.g.,
 * X-Setup-Token) use this middleware so the Bearer path still resolves userId.
 */
final class IdentityOptionalAuthMiddleware
{
    public function __construct(
        private readonly JwksProviderContract $jwks,
        private readonly SessionService $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token !== null) {
            try {
                $claims = $this->validateJwt($token);
                $this->sessions->validateSession($claims['session_id']);

                $request->attributes->set('identity.user_id',    $claims['sub']);
                $request->attributes->set('identity.session_id', $claims['session_id']);
                $request->attributes->set('identity.actor_type', $claims['actor_type'] ?? 'customer');
                $request->attributes->set('identity.device_id',  $claims['device_id'] ?? '');
            } catch (\Throwable) {
                // Optional — ignore invalid tokens and continue unauthenticated
            }
        }

        return $next($request);
    }

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

        $config->validator()->assert($parsed, new LooseValidAt(
            new SystemClock(new \DateTimeZone('UTC')),
        ));

        return array_map(
            static fn ($claim) => $claim,
            $parsed->claims()->all(),
        );
    }
}
