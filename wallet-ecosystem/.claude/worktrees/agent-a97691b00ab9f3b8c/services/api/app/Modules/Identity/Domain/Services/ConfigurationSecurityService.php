<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Domain\Exceptions\ConfigurationConflictException;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;

/**
 * Single access point for all 25 identity/security configuration keys.
 * All reads go through ConfigurationServiceInterface — no direct SQL to platform.configuration_values.
 * Security minimum enforcement (T046) lives here so every service consumes pre-validated values.
 */
final class ConfigurationSecurityService
{
    /** Security minimums from spec Section 8.1 */
    private const MINIMUMS = [
        'auth.otp.ttl'                  => 60,
        'auth.otp.max_attempts'         => 1,
        'auth.otp.max_sends_per_hour'   => 1,
        'auth.pin.length'               => 6,
        'auth.lockout.max_failed_attempts' => 1,
        'auth.lockout.initial_duration' => 60,
        'auth.step_up_threshold'        => 0,
    ];

    public function __construct(
        private readonly ConfigurationServiceInterface $config,
    ) {}

    // -------------------------------------------------------------------------
    // Step-up
    // -------------------------------------------------------------------------

    /** Transaction amount above which step-up MFA is required (GAP-027 resolved). */
    public function getStepUpThreshold(): int
    {
        return (int) $this->config->getOrDefault('auth.step_up_threshold', 10000);
    }

    /** Currency ISO code for the step-up threshold. */
    public function getStepUpThresholdCurrency(): string
    {
        return (string) $this->config->getOrDefault('auth.step_up_threshold_currency', 'BWP');
    }

    /** Lifetime (seconds) of an issued step-up access token. Read from config; never hard-coded. */
    public function getStepUpTokenTtl(): int
    {
        return (int) $this->config->getOrDefault('auth.step_up.token_ttl', 300);
    }

    /** Lifetime (seconds) of an issued service-identity token. TBD — read from config. */
    public function getServiceTokenTtl(): int
    {
        return (int) $this->config->getOrDefault('auth.service_token.ttl', 900);
    }

    // -------------------------------------------------------------------------
    // Session inactivity
    // -------------------------------------------------------------------------

    public function getSessionInactivityTtlAdmin(): int
    {
        return (int) $this->config->getOrDefault('auth.session.inactivity_ttl_admin', 900);
    }

    public function getSessionInactivityTtlCustomer(): int
    {
        return (int) $this->config->getOrDefault('auth.session.inactivity_ttl_customer', 1800);
    }

    public function getSessionInactivityTtlAgent(): int
    {
        return (int) $this->config->getOrDefault('auth.session.inactivity_ttl_agent', 1800);
    }

    // -------------------------------------------------------------------------
    // Refresh token
    // -------------------------------------------------------------------------

    public function getRefreshTokenTtl(): int
    {
        return (int) $this->config->getOrDefault('auth.refresh_token.ttl', 2592000);
    }

    public function isRefreshTokenRotationEnabled(): bool
    {
        return filter_var(
            $this->config->getOrDefault('auth.refresh_token.rotation_enabled', true),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    public function isRefreshTokenReuseDetectionEnabled(): bool
    {
        return filter_var(
            $this->config->getOrDefault('auth.refresh_token.reuse_detection_enabled', true),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    // -------------------------------------------------------------------------
    // OTP
    // -------------------------------------------------------------------------

    public function getOtpTtl(): int
    {
        return (int) $this->config->getOrDefault('auth.otp.ttl', 300);
    }

    public function getOtpMaxAttempts(): int
    {
        return (int) $this->config->getOrDefault('auth.otp.max_attempts', 5);
    }

    public function getOtpResendCooldown(): int
    {
        return (int) $this->config->getOrDefault('auth.otp.resend_cooldown', 60);
    }

    public function getOtpMaxSendsPerHour(): int
    {
        return (int) $this->config->getOrDefault('auth.otp.max_sends_per_hour', 5);
    }

    public function getOtpProvider(): string
    {
        return (string) $this->config->getOrDefault('auth.otp.provider', 'configured');
    }

    // -------------------------------------------------------------------------
    // PIN
    // -------------------------------------------------------------------------

    public function getPinLength(): int
    {
        return (int) $this->config->getOrDefault('auth.pin.length', 6);
    }

    public function isPinRejectSequential(): bool
    {
        return filter_var(
            $this->config->getOrDefault('auth.pin.reject_sequential', true),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    public function isPinRejectRepeated(): bool
    {
        return filter_var(
            $this->config->getOrDefault('auth.pin.reject_repeated', true),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    public function isPinRejectCommon(): bool
    {
        return filter_var(
            $this->config->getOrDefault('auth.pin.reject_common', true),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    // -------------------------------------------------------------------------
    // Lockout
    // -------------------------------------------------------------------------

    public function getLockoutMaxFailedAttempts(): int
    {
        return (int) $this->config->getOrDefault('auth.lockout.max_failed_attempts', 5);
    }

    public function getLockoutInitialDuration(): int
    {
        return (int) $this->config->getOrDefault('auth.lockout.initial_duration', 900);
    }

    public function isLockoutProgressiveEnabled(): bool
    {
        return filter_var(
            $this->config->getOrDefault('auth.lockout.progressive_enabled', true),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    // -------------------------------------------------------------------------
    // MFA
    // -------------------------------------------------------------------------

    public function isAdminTotpEnabled(): bool
    {
        return filter_var(
            $this->config->getOrDefault('auth.mfa.admin.totp_enabled', true),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    public function isAdminFido2Enabled(): bool
    {
        return filter_var(
            $this->config->getOrDefault('auth.mfa.admin.fido2_enabled', true),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    public function isAdminSmsEnabled(): bool
    {
        return filter_var(
            $this->config->getOrDefault('auth.mfa.admin.sms_enabled', false),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    // -------------------------------------------------------------------------
    // Biometric
    // -------------------------------------------------------------------------

    public function isBiometricEnabled(): bool
    {
        return filter_var(
            $this->config->getOrDefault('auth.biometric.enabled', true),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    // -------------------------------------------------------------------------
    // Retention
    // -------------------------------------------------------------------------

    public function getSessionRetentionDays(): int
    {
        return (int) $this->config->getOrDefault('auth.session.retention_days', 90);
    }

    // -------------------------------------------------------------------------
    // Audit security
    // -------------------------------------------------------------------------

    public function getIpAuditHashAlgorithm(): string
    {
        return (string) $this->config->getOrDefault('security.ip_audit.hash_algorithm', 'HMAC-SHA256');
    }

    // -------------------------------------------------------------------------
    // Rate limiting (spec §24.6 — production values TBD; set via platform.configuration_values)
    // -------------------------------------------------------------------------

    /** Max requests/minute on unauthenticated public endpoints (login, register, OTP). */
    public function getRateLimitPublic(): int
    {
        return (int) $this->config->getOrDefault('auth.rate_limit.public', 60);
    }

    /** Max requests/minute on the token refresh endpoint. */
    public function getRateLimitRefresh(): int
    {
        return (int) $this->config->getOrDefault('auth.rate_limit.refresh', 60);
    }

    /** Max requests/minute on Bearer-authenticated user endpoints. */
    public function getRateLimitAuth(): int
    {
        return (int) $this->config->getOrDefault('auth.rate_limit.auth', 60);
    }

    /** Max requests/minute on admin-only endpoints. */
    public function getRateLimitAdmin(): int
    {
        return (int) $this->config->getOrDefault('auth.rate_limit.admin', 60);
    }

    // -------------------------------------------------------------------------
    // Security minimum enforcement (T046; FR-E01-043; AC-024)
    // -------------------------------------------------------------------------

    /**
     * Validate a proposed configuration value against platform security minimums.
     * Throws ConfigurationConflictException when the value is below the minimum.
     * Called by the Configuration Admin API before persisting any update.
     */
    public function validateUpdate(string $key, mixed $value): void
    {
        if (!array_key_exists($key, self::MINIMUMS)) {
            return;
        }

        $minimum = self::MINIMUMS[$key];
        $numeric = filter_var($value, FILTER_VALIDATE_FLOAT);

        if ($numeric === false) {
            throw ConfigurationConflictException::invalidValue($key, 'expected a numeric value');
        }

        if ($numeric < $minimum) {
            throw ConfigurationConflictException::belowMinimum($key, $minimum);
        }
    }
}
