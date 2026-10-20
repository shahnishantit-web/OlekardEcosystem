<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Cache;

final class IdentityRedisKeys
{
    public const REDIS_CONNECTION = 'identity';

    public static function revokedSession(string $sessionId): string
    {
        return "identity:revoked:{$sessionId}";
    }

    public static function revokedRefreshToken(string $tokenHash): string
    {
        return "identity:refresh_revoked:{$tokenHash}";
    }

    public static function otpRate(string $identifier): string
    {
        return "identity:otp_rate:{$identifier}";
    }

    public static function loginRate(string $ipHash): string
    {
        return "identity:login_rate:{$ipHash}";
    }

    public const JWKS = 'identity:jwks';

    public static function deviceVerifyChallenge(string $challengeId): string
    {
        return "identity:device_verify:{$challengeId}";
    }

    /** Maps SHA-256(rawSetupToken) → user_id; TTL = 10 minutes. Single-use. */
    public static function setupToken(string $tokenHash): string
    {
        return "identity:setup_token:{$tokenHash}";
    }

    /** Maps SHA-256(rawMfaToken) → JSON {user_id, device_id, actor_type, correlation_id}; TTL = 5 minutes. */
    public static function mfaPending(string $tokenHash): string
    {
        return "identity:mfa_pending:{$tokenHash}";
    }

    /** Maps enrollment_id → JSON {user_id, totp_secret}; TTL = 10 minutes. Single-use. */
    public static function mfaEnrollment(string $enrollmentId): string
    {
        return "identity:mfa_enroll:{$enrollmentId}";
    }

    /** MFA failure counter per user; TTL aligns with lockout window. */
    public static function mfaFailures(string $userId): string
    {
        return "identity:mfa_failures:{$userId}";
    }

    /** Maps SHA-256(rawStepUpToken) → JSON {user_id, session_id, actor_type, scope, correlation_id}; TTL = 300 s. Single-use. */
    public static function stepUpPending(string $tokenHash): string
    {
        return "identity:step_up_pending:{$tokenHash}";
    }
}
