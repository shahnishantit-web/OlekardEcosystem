<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Application\Commands\VerifyMfaCommand;
use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Events\MfaChallengeVerifiedEvent;
use App\Modules\Identity\Domain\Events\MfaDisabledEvent;
use App\Modules\Identity\Domain\Events\MfaEnabledEvent;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Infrastructure\Cache\IdentityRedisKeys;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use chillerlan\Authenticator\Authenticator;
use chillerlan\Authenticator\AuthenticatorOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * MFA enrollment, verification, and lifecycle management.
 *
 * TOTP: uses chillerlan/php-authenticator (RFC 6238; SHA1/6-digit/30 s period).
 * FIDO2: sign_count replay detection enforced per spec Section 11, FR-E01-049.
 *        Phase 12 stores credential data; full attestation crypto ships in Phase 21.
 */
final class MfaService
{
    private const MFA_TOKEN_TTL    = 300;   // 5 minutes
    private const ENROLL_TTL       = 600;   // 10 minutes
    private const BACKUP_CODE_COUNT = 8;

    public function __construct(
        private readonly CredentialRepositoryInterface $credentials,
        private readonly ConfigurationSecurityService  $config,
        private readonly EventPublisherInterface       $events,
    ) {}

    // -------------------------------------------------------------------------
    // TOTP Enrollment
    // -------------------------------------------------------------------------

    /**
     * Phase 1 of TOTP enrollment: generate secret, return otpauth:// URI.
     * Secret is stored in Redis until confirmation; NOT persisted to DB yet.
     *
     * @return array{enrollment_id: string, totp_uri: string, expires_at: string}
     */
    public function initiateTotpEnrollment(string $userId, string $correlationId): array
    {
        $options = new AuthenticatorOptions;
        $auth    = new Authenticator($options);
        $secret  = $auth->createSecret(32);
        $uri     = $auth->getUri("OleKard:{$userId}", 'OleKard');

        $enrollmentId = (string) Str::uuid();
        $expiresAt    = now()->addSeconds(self::ENROLL_TTL);

        Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)->setex(
            IdentityRedisKeys::mfaEnrollment($enrollmentId),
            self::ENROLL_TTL,
            json_encode(['user_id' => $userId, 'totp_secret' => $secret], JSON_THROW_ON_ERROR),
        );

        return [
            'enrollment_id' => $enrollmentId,
            'totp_uri'      => $uri,
            'expires_at'    => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Phase 2 of TOTP enrollment: verify first code, persist credential.
     * Deletes the pending enrollment from Redis after success.
     *
     * @return array{enrolled_at: string, backup_codes: string[]}
     * @throws InvalidCredentialsException when enrollment_id or code is invalid
     */
    public function confirmTotpEnrollment(
        string $userId,
        string $enrollmentId,
        string $totpCode,
        string $correlationId,
        string $actorType = 'CUSTOMER',
    ): array {
        $redis = Redis::connection(IdentityRedisKeys::REDIS_CONNECTION);
        $raw   = $redis->get(IdentityRedisKeys::mfaEnrollment($enrollmentId));

        if ($raw === null || $raw === false) {
            throw InvalidCredentialsException::make();
        }

        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (($payload['user_id'] ?? '') !== $userId) {
            throw InvalidCredentialsException::make();
        }

        $secret = (string) ($payload['totp_secret'] ?? '');
        if (!$this->verifyTotpCode($secret, $totpCode)) {
            throw InvalidCredentialsException::make();
        }

        // Revoke any existing TOTP credential before re-enrolling
        $existing = $this->credentials->findByUserAndType($userId, 'mfa_totp');
        if ($existing !== null) {
            $this->credentials->updateStatus($existing->id, 'revoked');
        }

        DB::connection('platform')->transaction(function () use ($userId, $secret, $correlationId, $actorType): void {
            $this->credentials->create([
                'user_id'         => $userId,
                'credential_type' => 'mfa_totp',
                'credential_hash' => $secret, // base32 TOTP secret; hidden in model serialization
                'status'          => 'active',
            ]);

            $this->events->publish(new MfaEnabledEvent(
                userId:        $userId,
                actorId:       $userId,
                correlationId: $correlationId,
                actorType:     $actorType,
                method:        'totp',
            ));
        });

        $redis->del(IdentityRedisKeys::mfaEnrollment($enrollmentId));

        return [
            'enrolled_at'  => now()->toIso8601String(),
            'backup_codes' => $this->generateBackupCodes(),
        ];
    }

    // -------------------------------------------------------------------------
    // FIDO2 Enrollment (Phase 12 simplified — full attestation crypto in Phase 21)
    // -------------------------------------------------------------------------

    /**
     * Phase 1 of FIDO2 enrollment: generate and cache a registration challenge.
     *
     * @return array{enrollment_id: string, challenge: string, rp_id: string, expires_at: string}
     */
    public function initiateFido2Enrollment(string $userId, string $correlationId): array
    {
        $enrollmentId = (string) Str::uuid();
        $challenge    = base64_encode(random_bytes(32));
        $expiresAt    = now()->addSeconds(self::ENROLL_TTL);

        Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)->setex(
            IdentityRedisKeys::mfaEnrollment('fido2-' . $enrollmentId),
            self::ENROLL_TTL,
            json_encode(['user_id' => $userId, 'challenge' => $challenge], JSON_THROW_ON_ERROR),
        );

        return [
            'enrollment_id' => $enrollmentId,
            'challenge'     => $challenge,
            'rp_id'         => config('app.url', 'identity-service'),
            'expires_at'    => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Phase 2 of FIDO2 enrollment: store credential_id, public_key, initial sign_count.
     * Phase 12: stores client-provided data; attestation crypto deferred to Phase 21.
     *
     * @throws InvalidCredentialsException when enrollment_id is invalid or user_id mismatches
     */
    public function confirmFido2Enrollment(
        string $userId,
        string $enrollmentId,
        string $credentialId,
        string $publicKey,
        int    $signCount,
        string $correlationId,
        string $actorType = 'CUSTOMER',
    ): void {
        $redis = Redis::connection(IdentityRedisKeys::REDIS_CONNECTION);
        $raw   = $redis->get(IdentityRedisKeys::mfaEnrollment('fido2-' . $enrollmentId));

        if ($raw === null || $raw === false) {
            throw InvalidCredentialsException::make();
        }

        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (($payload['user_id'] ?? '') !== $userId) {
            throw InvalidCredentialsException::make();
        }

        $existing = $this->credentials->findByUserAndType($userId, 'mfa_fido2');
        if ($existing !== null) {
            $this->credentials->updateStatus($existing->id, 'revoked');
        }

        DB::connection('platform')->transaction(function () use ($userId, $credentialId, $publicKey, $signCount, $correlationId, $actorType): void {
            $this->credentials->create([
                'user_id'             => $userId,
                'credential_type'     => 'mfa_fido2',
                'fido2_credential_id' => $credentialId,
                'fido2_public_key'    => $publicKey,
                'fido2_sign_count'    => $signCount,
                'status'              => 'active',
            ]);

            $this->events->publish(new MfaEnabledEvent(
                userId:        $userId,
                actorId:       $userId,
                correlationId: $correlationId,
                actorType:     $actorType,
                method:        'fido2',
            ));
        });

        $redis->del(IdentityRedisKeys::mfaEnrollment('fido2-' . $enrollmentId));
    }

    // -------------------------------------------------------------------------
    // MFA Verification during Login (T120)
    // -------------------------------------------------------------------------

    /**
     * Verify MFA code presented after primary credential login.
     * Returns the session payload stored in the mfa_pending Redis key.
     *
     * @return array{user_id: string, device_id: string, actor_type: string, correlation_id: string}
     * @throws InvalidCredentialsException on invalid token or wrong code
     */
    public function verifyMfaToken(VerifyMfaCommand $cmd): array
    {
        $tokenHash = hash('sha256', $cmd->mfaToken);
        $redis     = Redis::connection(IdentityRedisKeys::REDIS_CONNECTION);
        $raw       = $redis->get(IdentityRedisKeys::mfaPending($tokenHash));

        if ($raw === null || $raw === false) {
            throw InvalidCredentialsException::make();
        }

        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $userId  = (string) ($payload['user_id'] ?? '');

        $valid = match ($cmd->method) {
            'totp'  => $this->verifyUserTotp($userId, $cmd->code),
            'fido2' => $this->verifyUserFido2($userId, $cmd->code),
            default => false,
        };

        if (!$valid) {
            $this->incrementMfaFailures($userId);
            throw InvalidCredentialsException::make();
        }

        $redis->del(IdentityRedisKeys::mfaPending($tokenHash));
        $this->resetMfaFailures($userId);

        $actorType = (string) ($payload['actor_type'] ?? 'CUSTOMER');
        $this->events->publish(new MfaChallengeVerifiedEvent(
            userId:        $userId,
            actorId:       $userId,
            correlationId: $cmd->correlationId,
            actorType:     $actorType,
            method:        $cmd->method,
        ));

        return $payload;
    }

    /**
     * Create a Redis mfa_pending entry and return the opaque mfa_token.
     * Called by AuthenticationService when MFA is enrolled and primary auth succeeds.
     */
    public function issueMfaPendingToken(
        string $userId,
        string $deviceId,
        string $actorType,
        string $correlationId,
    ): string {
        $rawToken  = base64_encode(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)->setex(
            IdentityRedisKeys::mfaPending($tokenHash),
            self::MFA_TOKEN_TTL,
            json_encode([
                'user_id'        => $userId,
                'device_id'      => $deviceId,
                'actor_type'     => $actorType,
                'correlation_id' => $correlationId,
            ], JSON_THROW_ON_ERROR),
        );

        return $rawToken;
    }

    // -------------------------------------------------------------------------
    // Method Discovery
    // -------------------------------------------------------------------------

    /** @return string[] list of enrolled MFA method names for this user */
    public function getMfaMethods(string $userId): array
    {
        $methods = [];
        foreach (['mfa_totp' => 'totp', 'mfa_fido2' => 'fido2'] as $credType => $methodName) {
            $cred = $this->credentials->findByUserAndType($userId, $credType);
            if ($cred !== null && $cred->status === 'active') {
                $methods[] = $methodName;
            }
        }
        return $methods;
    }

    public function hasMfaEnrolled(string $userId): bool
    {
        return count($this->getMfaMethods($userId)) > 0;
    }

    // -------------------------------------------------------------------------
    // Disable MFA (T121 audit is triggered by MfaController)
    // -------------------------------------------------------------------------

    public function disableMfa(string $userId, string $method, string $correlationId = '', string $actorType = 'CUSTOMER'): void
    {
        $credType = match ($method) {
            'totp'  => 'mfa_totp',
            'fido2' => 'mfa_fido2',
            default => null,
        };

        if ($credType === null) {
            return;
        }

        $cred = $this->credentials->findByUserAndType($userId, $credType);
        if ($cred === null) {
            return;
        }

        DB::connection('platform')->transaction(function () use ($userId, $cred, $method, $correlationId, $actorType): void {
            $this->credentials->updateStatus($cred->id, 'revoked');

            $this->events->publish(new MfaDisabledEvent(
                userId:        $userId,
                actorId:       $userId,
                correlationId: $correlationId,
                actorType:     $actorType,
                method:        $method,
            ));
        });
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function verifyTotpCode(string $base32Secret, string $code): bool
    {
        $auth = new Authenticator(new AuthenticatorOptions);
        $auth->setSecret($base32Secret);
        return $auth->verify($code);
    }

    private function verifyUserTotp(string $userId, string $code): bool
    {
        $cred = $this->credentials->findByUserAndType($userId, 'mfa_totp');
        if ($cred === null || $cred->status !== 'active') {
            return false;
        }
        // credential_hash stores the base32 TOTP secret (not an Argon2id hash)
        return $this->verifyTotpCode((string) $cred->credential_hash, $code);
    }

    /**
     * FIDO2 assertion verification (Phase 12: sign_count check; signature crypto in Phase 21).
     * The $code parameter carries the new sign_count as a string for testability.
     * T116: reject if new sign_count ≤ stored sign_count (replay detection, FR-E01-049).
     */
    private function verifyUserFido2(string $userId, string $newSignCountStr): bool
    {
        $cred = $this->credentials->findByUserAndType($userId, 'mfa_fido2');
        if ($cred === null || $cred->status !== 'active') {
            return false;
        }

        $newSignCount = (int) $newSignCountStr;
        if ($newSignCount <= $cred->fido2_sign_count) {
            // Replay attack detected (FR-E01-049; T116)
            return false;
        }

        $this->credentials->updateFido2SignCount($cred->id, $newSignCount);
        return true;
    }

    private function incrementMfaFailures(string $userId): void
    {
        $key   = IdentityRedisKeys::mfaFailures($userId);
        $redis = Redis::connection(IdentityRedisKeys::REDIS_CONNECTION);
        $redis->incr($key);
        $redis->expire($key, $this->config->getLockoutInitialDuration());
    }

    private function resetMfaFailures(string $userId): void
    {
        Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)
            ->del(IdentityRedisKeys::mfaFailures($userId));
    }

    /** @return string[] */
    private function generateBackupCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::BACKUP_CODE_COUNT; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)));
        }
        return $codes;
    }
}
