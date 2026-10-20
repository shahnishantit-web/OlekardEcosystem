<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Contracts\AuthenticatedActor;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Contracts\JwksProviderContract;
use App\Modules\Identity\Domain\Contracts\ServiceActor;
use App\Modules\Identity\Domain\Contracts\SessionRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\UserContactInfo;
use App\Modules\Identity\Domain\Services\ConfigurationSecurityService;
use App\Modules\Identity\Infrastructure\Cache\IdentityRedisKeys;
use Illuminate\Support\Facades\Redis;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;

/**
 * Concrete implementation of IdentityModuleContract.
 * Used by cross-module callers (E04, E09, etc.) to verify tokens and revoke sessions
 * without direct coupling to internal Identity services.
 *
 * Token verification uses JWKS key material via JwksProviderContract — same trust
 * anchors as the IdentityAuthMiddleware. Returns null on any invalid/expired token
 * rather than throwing, so callers can handle gracefully.
 */
final class IdentityModule implements IdentityModuleContract
{
    public function __construct(
        private readonly JwksProviderContract         $jwks,
        private readonly SessionRepositoryInterface   $sessions,
        private readonly ConfigurationSecurityService $config,
    ) {}

    /**
     * Resolve authenticated actor from a standard user Bearer JWT.
     * Returns null when the token is absent, expired, signature-invalid, or revoked.
     */
    public function resolveActorFromToken(string $bearerToken): ?AuthenticatedActor
    {
        try {
            $claims = $this->parseJwt($bearerToken);
        } catch (\Throwable) {
            return null;
        }

        return new AuthenticatedActor(
            userId:    (string) ($claims['sub'] ?? ''),
            sessionId: (string) ($claims['session_id'] ?? ''),
            actorType: (string) ($claims['actor_type'] ?? 'USER'),
            roles:     (array)  ($claims['roles'] ?? []),
            deviceId:  isset($claims['device_id']) && $claims['device_id'] !== null
                ? (string) $claims['device_id']
                : null,
        );
    }

    /**
     * Resolve actor from a service-identity JWT (actor_type=SERVICE).
     * Returns null when the token is invalid or does not carry actor_type=SERVICE.
     */
    public function resolveServiceActor(string $bearerToken): ?ServiceActor
    {
        try {
            $claims = $this->parseJwt($bearerToken);
        } catch (\Throwable) {
            return null;
        }

        if (($claims['actor_type'] ?? '') !== 'SERVICE') {
            return null;
        }

        return new ServiceActor(
            serviceId:  (string) ($claims['service_id'] ?? $claims['sub'] ?? ''),
            actorType:  'SERVICE',
            scopes:     (array)  ($claims['scopes'] ?? []),
            onBehalfOf: isset($claims['on_behalf_of']) && $claims['on_behalf_of'] !== null
                ? (string) $claims['on_behalf_of']
                : null,
        );
    }

    /**
     * Check whether a step-up token grants the requested scope.
     * Validates: ES256 signature, expiry, aud = "step-up:{requiredScope}", step_up_scope claim.
     */
    public function verifyStepUpScope(string $stepUpToken, string $requiredScope): bool
    {
        try {
            $claims = $this->parseJwt($stepUpToken);
        } catch (\Throwable) {
            return false;
        }

        $aud = $claims['aud'] ?? null;
        if (is_string($aud)) {
            $aud = [$aud];
        } elseif (!is_array($aud)) {
            return false;
        }

        $expectedAud = 'step-up:' . $requiredScope;
        if (!in_array($expectedAud, $aud, true)) {
            return false;
        }

        return ($claims['step_up_scope'] ?? '') === $requiredScope;
    }

    /**
     * Revoke a session cross-module. Marks it revoked in the DB and caches revocation in Redis.
     * Intended for admin/E04 usage; no full audit here — the calling module handles its own audit.
     */
    public function revokeSession(string $sessionId, string $revokedByActorId): void
    {
        $session = $this->sessions->findById($sessionId);
        if ($session === null) {
            return;
        }

        $this->sessions->updateStatus($sessionId, 'revoked');

        Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)->setex(
            IdentityRedisKeys::revokedSession($sessionId),
            $this->config->getRefreshTokenTtl(),
            '1',
        );
    }

    // -------------------------------------------------------------------------
    // E02 extensions (ADR-E02-001) — implemented in E01 Phase 34 (T239–T244)
    // -------------------------------------------------------------------------

    public function getUserContactInfo(string $userId): ?UserContactInfo
    {
        throw new \BadMethodCallException(
            'IdentityModule::getUserContactInfo() is not yet implemented. Complete E01 Phase 34 (T239–T244).'
        );
    }

    public function updateUserContact(string $userId, ?string $email, ?string $mobile): void
    {
        throw new \BadMethodCallException(
            'IdentityModule::updateUserContact() is not yet implemented. Complete E01 Phase 34 (T239–T244).'
        );
    }

    /**
     * Parse and ES256-verify a JWT; return claims on success.
     * Extracts public key from the stored private key (same approach as IdentityAuthMiddleware).
     *
     * @throws \Throwable on invalid signature, expiry, or malformed token
     */
    private function parseJwt(string $rawJwt): array
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
