<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Middleware;

use App\Modules\Identity\Domain\Contracts\JwksProviderContract;
use App\Modules\Identity\Domain\Services\RuntimeSecurityPolicy;
use App\Modules\Identity\Domain\Services\SessionService;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Ramsey\Uuid\Uuid;
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
                $session = $this->sessions->validateSession($claims['session_id']);

                $request->attributes->set('identity.user_id', $claims['sub']);
                $request->attributes->set('identity.session_id', $claims['session_id']);
                $request->attributes->set('identity.actor_type', $claims['actor_type'] ?? 'customer');
                $request->attributes->set('identity.device_id', $claims['device_id'] ?? '');
                app(RuntimeSecurityPolicy::class)->authorize($request, $session);
            } catch (HttpResponseException $e) {
                throw $e;
            } catch (\Throwable) {
                // Optional — ignore invalid tokens and continue unauthenticated
            }
        }

        return $next($request);
    }

    private function validateJwt(string $rawJwt): array
    {
        $signingKey = $this->jwks->getSigningKey();
        if ($rawJwt === '' || $signingKey->privateKeyPem === '') {
            throw new \RuntimeException('Missing access token or signing key.');
        }
        $privateKey = openssl_pkey_get_private($signingKey->privateKeyPem);
        if ($privateKey === false) {
            throw new \RuntimeException('Invalid signing key.');
        }
        $privDetails = openssl_pkey_get_details($privateKey);

        if ($privDetails === false) {
            throw new \RuntimeException('Cannot extract public key for JWT verification.');
        }

        $publicKeyPem = $privDetails['key'];
        if ($publicKeyPem === '') {
            throw new \RuntimeException('Missing verification key.');
        }
        $audience = (string) config('app.url', 'identity-service');
        if ($audience === '') {
            throw new \RuntimeException('Missing token audience.');
        }

        $config = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($signingKey->privateKeyPem),
            InMemory::plainText($publicKeyPem),
        );

        $parsed = $config->parser()->parse($rawJwt);
        if (! $parsed instanceof UnencryptedToken) {
            throw new \RuntimeException('Invalid access token.');
        }
        foreach (['sub', 'session_id'] as $claim) {
            if (! is_string($parsed->claims()->get($claim)) || ! Uuid::isValid($parsed->claims()->get($claim))) {
                throw new \RuntimeException('Invalid access claims.');
            }
        }

        $config->validator()->assert($parsed,
            new SignedWith($config->signer(), $config->verificationKey()),
            new PermittedFor($audience), new LooseValidAt(
                new SystemClock(new \DateTimeZone('UTC')),
            ));

        return array_map(
            static fn ($claim) => $claim,
            $parsed->claims()->all(),
        );
    }
}
