<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Contracts\ActorStepUpVerifierContract;
use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\AuthenticatedActor;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Contracts\JwksProviderContract;
use App\Modules\Identity\Domain\Contracts\ServiceActor;
use App\Modules\Identity\Domain\Contracts\SessionRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\UserContactInfo;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Services\ConfigurationSecurityService;
use App\Modules\Identity\Domain\Services\PermissionResolverService;
use App\Modules\Identity\Infrastructure\Cache\IdentityRedisKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;

/**
 * Concrete implementation of IdentityModuleContract.
 * Used by cross-module callers (E04, E09, etc.) to verify tokens and revoke sessions
 * without direct coupling to internal Identity services.
 *
 * Token verification uses JWKS key material via JwksProviderContract — same trust
 * anchors as the IdentityAuthMiddleware. Returns null on any invalid/expired token
 * rather than throwing, so callers can handle gracefully.
 */
final class IdentityModule implements ActorStepUpVerifierContract, IdentityModuleContract
{
    public function __construct(
        private readonly JwksProviderContract $jwks,
        private readonly SessionRepositoryInterface $sessions,
        private readonly ConfigurationSecurityService $config,
        private readonly UserRepositoryInterface $users,
        private readonly PermissionResolverService $permissionResolver,
        private readonly CredentialRepositoryInterface $credentials,
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
            userId: (string) ($claims['sub'] ?? ''),
            sessionId: (string) ($claims['session_id'] ?? ''),
            actorType: (string) ($claims['actor_type'] ?? 'USER'),
            roles: (array) ($claims['roles'] ?? []),
            deviceId: isset($claims['device_id']) && $claims['device_id'] !== null
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
            serviceId: (string) ($claims['service_id'] ?? $claims['sub'] ?? ''),
            actorType: 'SERVICE',
            scopes: (array) ($claims['scopes'] ?? []),
            onBehalfOf: isset($claims['on_behalf_of']) && $claims['on_behalf_of'] !== null
                ? (string) $claims['on_behalf_of']
                : null,
        );
    }

    /**
     * Check whether a step-up token grants the requested scope.
     * Validates: ES256 signature, expiry, aud = "step-up:{requiredScope}", step_up_scope claim.
     */
    public function verifyStepUpForActor(string $token, string $scope, string $actorId, string $sessionId): bool
    {
        if ($actorId === '' || $sessionId === '' || ! $this->verifyStepUpScope($token, $scope)) {
            return false;
        }
        try {
            $claims = $this->parseJwt($token);
        } catch (\Throwable) {
            return false;
        }

        return ($claims['sub'] ?? null) === $actorId && ($claims['session_id'] ?? null) === $sessionId;
    }

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
        } elseif (! is_array($aud)) {
            return false;
        }

        $expectedAud = 'step-up:'.$requiredScope;
        if (! in_array($expectedAud, $aud, true)) {
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
    // E02 extensions (ADR-E02-001)
    // -------------------------------------------------------------------------

    public function getUserContactInfo(string $userId): ?UserContactInfo
    {
        $user = $this->users->findById($userId);

        if ($user === null) {
            return null;
        }

        return new UserContactInfo(
            userId: $user->id,
            email: $user->email,
            mobileNumber: $user->mobile_number,
            actorType: $user->actor_type,
        );
    }

    public function updateUserContact(string $userId, ?string $email, ?string $mobile): void
    {
        $this->users->updateContactInfo($userId, $email, $mobile);
    }

    // -------------------------------------------------------------------------
    // E04: hasPermission
    // -------------------------------------------------------------------------

    public function hasPermission(string $actorId, string $permission): bool
    {
        return $this->permissionResolver->hasPermission($actorId, $permission);
    }

    // -------------------------------------------------------------------------
    // E09 extensions — Recipient Lookup
    // -------------------------------------------------------------------------

    public function findCustomerIdByMobile(string $mobileNumber): ?string
    {
        $user = $this->users->findByMobile($mobileNumber);

        if ($user === null || $user->status !== 'active' || $user->actor_type !== 'CUSTOMER') {
            return null;
        }

        return $user->id;
    }

    // -------------------------------------------------------------------------
    // E08/E09 extensions — Transaction PIN verification
    // -------------------------------------------------------------------------

    public function verifyTransactionPin(string $actorId, string $pin, bool $requireStepUp = false): bool
    {
        $user = $this->users->findById($actorId);
        $credential = $this->credentials->findByUserAndType($actorId, 'pin');
        if ($user === null || $user->status !== 'active' || $credential === null
            || $credential->status !== 'active'
            || ($credential->expires_at !== null && $credential->expires_at->isPast())) return false;
        $correlation = request()->header('X-Correlation-ID', (string) \Illuminate\Support\Str::uuid7());
        $lockout = app(\App\Modules\Identity\Domain\Services\LockoutService::class);
        try {
            $lockout->assertNotLocked($user, $correlation, hash('sha256', request()->ip() ?? ''));
            if (! password_verify($pin, (string) $credential->credential_hash)) {
                $lockout->recordFailure($user, $correlation, hash('sha256', request()->ip() ?? ''),
                    \App\Modules\Platform\Infrastructure\Security\PiiMasker::maskPhone($user->mobile_number ?? ''));
                return false;
            }
        } catch (\App\Modules\Identity\Domain\Exceptions\AccountLockedException) { return false; }
        if ($requireStepUp) {
            $scope = str_contains(request()->path(), 'withdrawals') ? 'withdrawal.confirm' : 'transfer.confirm';
            if (! $this->verifyStepUpForActor((string) request()->header('X-StepUp-Token', ''), $scope,
                $actorId, (string) request()->attributes->get('identity.session_id', ''))) return false;
        }
        return true;
    }

    public function getIdentitySnapshot(string $userId): ?array
    {
        throw new \BadMethodCallException('getIdentitySnapshot is not yet implemented — requires E01 Phase extension');
    }

    /**
     * Parse and ES256-verify a JWT; return claims on success.
     * Extracts public key from the stored private key (same approach as IdentityAuthMiddleware).
     *
     * @throws \Throwable on invalid signature, expiry, or malformed token
     */
    private function parseJwt(string $rawJwt): array
    {
        $signingKey = $this->jwks->getSigningKey();
        $privDetails = openssl_pkey_get_details(openssl_pkey_get_private($signingKey->privateKeyPem));

        if ($privDetails === false) {
            throw new \RuntimeException('Cannot extract public key for JWT verification.');
        }

        $publicKeyPem = $privDetails['key'];

        $config = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($signingKey->privateKeyPem),
            InMemory::plainText($publicKeyPem),
        );

        $parsed = $config->parser()->parse($rawJwt);
        if (! $parsed instanceof UnencryptedToken) {
            throw new \InvalidArgumentException('Unsupported JWT format');
        }

        $config->validator()->assert($parsed, new SignedWith($config->signer(), $config->verificationKey()), new LooseValidAt(
            new SystemClock(new \DateTimeZone('UTC')),
        ));

        return array_map(
            static fn ($claim) => $claim,
            $parsed->claims()->all(),
        );
    }
}
