<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Domain\Exceptions\ConfigurationConflictException;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Platform\Domain\ValueObjects\SecurityPolicyRegistry;
use App\Modules\Security\Domain\ValueObjects\SecurityAuditActions;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin-facing configuration management for the 25 E01 identity/security configuration keys.
 *
 * Handles:
 * - Listing all keys with metadata, current value, and last-updated provenance
 * - Updating values with typed validation, security minimum enforcement, and optimistic concurrency
 * - Cache invalidation after every successful write
 *
 * The static REGISTRY is the single source of truth for key metadata.
 * All reads/writes go to platform.configuration_values via the 'platform' DB connection.
 */
final class ConfigurationAdminService
{
    /**
     * Static metadata registry for all 25 E01 identity configuration keys.
     * Source of truth for: description, data_type, default_value, min, max,
     * allowed_values, and is_sensitive flags.
     *
     * @var array<string, array{
     *   description: string,
     *   data_type: string,
     *   default_value: string,
     *   is_sensitive: bool,
     *   min?: int|float,
     *   max?: int|float,
     *   allowed_values?: string[],
     * }>
     */
    public const REGISTRY = [
        'auth.otp.ttl' => [
            'description' => 'OTP validity window in seconds',
            'data_type' => 'integer',
            'default_value' => '300',
            'min' => 60,
            'max' => 3600,
            'is_sensitive' => false,
        ],
        'auth.otp.max_attempts' => [
            'description' => 'Maximum OTP verification attempts before challenge invalidation',
            'data_type' => 'integer',
            'default_value' => '5',
            'min' => 1,
            'max' => 10,
            'is_sensitive' => false,
        ],
        'auth.otp.resend_cooldown' => [
            'description' => 'Minimum seconds between OTP resend requests',
            'data_type' => 'integer',
            'default_value' => '60',
            'min' => 0,
            'max' => 300,
            'is_sensitive' => false,
        ],
        'auth.otp.max_sends_per_hour' => [
            'description' => 'Maximum OTP sends per account/channel per hour',
            'data_type' => 'integer',
            'default_value' => '5',
            'min' => 1,
            'max' => 20,
            'is_sensitive' => false,
        ],
        'auth.otp.provider' => [
            'description' => 'OTP provider identifier; actual credentials stored as platform secrets',
            'data_type' => 'string',
            'default_value' => 'configured',
            'allowed_values' => ['configured'],
            'is_sensitive' => false,
        ],
        'auth.pin.length' => [
            'description' => 'Required PIN length in digits',
            'data_type' => 'integer',
            'default_value' => '6',
            'min' => 6,
            'max' => 12,
            'is_sensitive' => false,
        ],
        'auth.pin.reject_sequential' => [
            'description' => 'Reject sequential PINs (e.g., 123456, 654321)',
            'data_type' => 'boolean',
            'default_value' => 'true',
            'is_sensitive' => false,
        ],
        'auth.pin.reject_repeated' => [
            'description' => 'Reject repeated-digit PINs (e.g., 111111, 000000)',
            'data_type' => 'boolean',
            'default_value' => 'true',
            'is_sensitive' => false,
        ],
        'auth.pin.reject_common' => [
            'description' => 'Reject known/common PIN values',
            'data_type' => 'boolean',
            'default_value' => 'true',
            'is_sensitive' => false,
        ],
        'auth.lockout.max_failed_attempts' => [
            'description' => 'Consecutive failures before account lockout',
            'data_type' => 'integer',
            'default_value' => '5',
            'min' => 1,
            'max' => 20,
            'is_sensitive' => false,
        ],
        'auth.lockout.initial_duration' => [
            'description' => 'Initial lockout duration in seconds',
            'data_type' => 'integer',
            'default_value' => '900',
            'min' => 60,
            'max' => 86400,
            'is_sensitive' => false,
        ],
        'auth.lockout.progressive_enabled' => [
            'description' => 'Increase lockout duration for repeated lockout episodes',
            'data_type' => 'boolean',
            'default_value' => 'true',
            'is_sensitive' => false,
        ],
        'auth.session.inactivity_ttl_admin' => [
            'description' => 'Admin session inactivity timeout in seconds',
            'data_type' => 'integer',
            'default_value' => '900',
            'min' => 300,
            'max' => 3600,
            'is_sensitive' => false,
        ],
        'auth.session.inactivity_ttl_customer' => [
            'description' => 'Customer session inactivity timeout in seconds',
            'data_type' => 'integer',
            'default_value' => '1800',
            'min' => 300,
            'max' => 86400,
            'is_sensitive' => false,
        ],
        'auth.session.inactivity_ttl_agent' => [
            'description' => 'Agent session inactivity timeout in seconds',
            'data_type' => 'integer',
            'default_value' => '1800',
            'min' => 300,
            'max' => 86400,
            'is_sensitive' => false,
        ],
        'auth.refresh_token.ttl' => [
            'description' => 'Refresh token lifetime in seconds',
            'data_type' => 'integer',
            'default_value' => '2592000',
            'min' => 3600,
            'max' => 7776000,
            'is_sensitive' => false,
        ],
        'auth.refresh_token.rotation_enabled' => [
            'description' => 'Rotate refresh token on every successful refresh',
            'data_type' => 'boolean',
            'default_value' => 'true',
            'is_sensitive' => false,
        ],
        'auth.refresh_token.reuse_detection_enabled' => [
            'description' => 'Revoke token family on refresh-token reuse detection',
            'data_type' => 'boolean',
            'default_value' => 'true',
            'is_sensitive' => false,
        ],
        'auth.step_up_threshold' => [
            'description' => 'Transaction amount above which step-up MFA is required (GAP-027; default 10000 BWP)',
            'data_type' => 'decimal',
            'default_value' => '10000',
            'min' => 0,
            'is_sensitive' => false,
        ],
        'auth.step_up_threshold_currency' => [
            'description' => 'Currency ISO code for the step-up threshold',
            'data_type' => 'string',
            'default_value' => 'BWP',
            'is_sensitive' => false,
        ],
        'auth.mfa.admin.totp_enabled' => [
            'description' => 'Allow TOTP MFA enrollment for Admin actors',
            'data_type' => 'boolean',
            'default_value' => 'true',
            'is_sensitive' => false,
        ],
        'auth.mfa.admin.fido2_enabled' => [
            'description' => 'Allow FIDO2/WebAuthn MFA enrollment for Admin actors',
            'data_type' => 'boolean',
            'default_value' => 'true',
            'is_sensitive' => false,
        ],
        'auth.mfa.admin.sms_enabled' => [
            'description' => 'Allow SMS MFA for Admin actors (disabled by default per FR-E01-049)',
            'data_type' => 'boolean',
            'default_value' => 'false',
            'is_sensitive' => false,
        ],
        'auth.biometric.enabled' => [
            'description' => 'Enable biometric authentication on supported devices',
            'data_type' => 'boolean',
            'default_value' => 'true',
            'is_sensitive' => false,
        ],
        'security.ip_audit.hash_algorithm' => [
            'description' => 'Algorithm for IP representation in audit records; HMAC secret is stored in platform secret manager',
            'data_type' => 'string',
            'default_value' => 'HMAC-SHA256',
            'allowed_values' => ['HMAC-SHA256'],
            'is_sensitive' => true,  // reveals audit security posture; value masked in API responses
        ],
    ];

    public function __construct(
        private readonly ConfigurationServiceInterface $configService,
        private readonly ConfigurationSecurityService $securityService,
        private readonly AuditServiceInterface $audit,
    ) {}

    /**
     * Return all 25 registered keys with current DB values and registry metadata.
     *
     * @return array<int, array{key: string, value: string, ...}>
     */
    public function getAllKeys(): array
    {
        $rows = DB::connection('platform')
            ->table('platform.configuration_values')
            ->whereIn('config_key', array_keys(self::REGISTRY))
            ->where('status', 'active')
            ->orderByDesc('version')
            ->get(['config_key', 'config_value', 'updated_at', 'last_updated_by'])
            ->keyBy('config_key');

        $result = [];
        foreach (self::REGISTRY as $key => $meta) {
            $row = $rows->get($key);

            $rawValue = $row?->config_value ?? $meta['default_value'];
            $isSensitive = $meta['is_sensitive'];

            $entry = [
                'key' => $key,
                'value' => $isSensitive ? '[REDACTED]' : $rawValue,
                'default_value' => $meta['default_value'],
                'description' => $meta['description'],
                'data_type' => $meta['data_type'],
                'is_sensitive' => $isSensitive,
                'last_updated_at' => $row?->updated_at
                    ? Carbon::parse($row->updated_at)->toIso8601String()
                    : null,
                'last_updated_by' => $row?->last_updated_by ?? 'system',
            ];

            if (isset($meta['min'])) {
                $entry['min'] = $meta['min'];
            }
            if (isset($meta['max'])) {
                $entry['max'] = $meta['max'];
            }
            if (isset($meta['allowed_values'])) {
                $entry['allowed_values'] = $meta['allowed_values'];
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Validate and persist an admin update for the given key.
     *
     * Enforces:
     * - Key exists in REGISTRY
     * - Submitted version matches current DB updated_at (optimistic concurrency)
     * - Value passes typed validation and security minimum (FR-E01-043)
     *
     * Returns old + new value provenance for audit and response.
     *
     * @throws ConfigurationConflictException on stale version, below-minimum, or invalid type
     * @throws \InvalidArgumentException on unknown key
     */
    public function update(
        string $key,
        string $newValue,
        string $version,
        string $adminId,
    ): array {
        if (SecurityPolicyRegistry::contains($key)) {
            throw ValidationException::withMessages(['key' => 'Security policy changes require approval through /v1/admin/platform/security-config.']);
        }
        if (! array_key_exists($key, self::REGISTRY)) {
            throw new \InvalidArgumentException("Configuration key '{$key}' is not in the registry.");
        }

        $meta = self::REGISTRY[$key];

        // Read current row directly (not cached — admin always sees live values)
        $row = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', $key)
            ->where('status', 'active')
            ->orderByDesc('version')
            ->first(['id', 'config_value', 'updated_at', 'last_updated_by']);

        $oldValue = $row?->config_value ?? $meta['default_value'];

        // Optimistic concurrency: compare submitted version with DB updated_at (second precision)
        if ($row !== null) {
            $dbTimestamp = Carbon::parse($row->updated_at)->startOfSecond();
            $reqTimestamp = Carbon::parse($version)->startOfSecond();

            if (! $dbTimestamp->equalTo($reqTimestamp)) {
                throw ConfigurationConflictException::staleVersion($key);
            }
        }

        // Typed validation
        $this->validateType($key, $newValue, $meta);

        // Security minimum enforcement (cannot weaken below platform minimum — BR-020)
        $this->securityService->validateUpdate($key, $newValue);

        $now = now();

        // Write: UPDATE the active row (single row per key; insertOrIgnore seed pattern)
        DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', $key)
            ->where('status', 'active')
            ->update([
                'config_value' => $newValue,
                'last_updated_by' => $adminId,
                'updated_at' => $now,
            ]);

        // Invalidate the ConfigurationService cache so new value takes effect immediately (T147)
        $this->configService->invalidate($key);

        // Emit security audit event for lockout and rate-limit config changes (E26 FR-013)
        $auditAction = $this->resolveAuditAction($key);
        if ($auditAction !== null) {
            $this->audit->record(new AuditEventData(
                action: $auditAction,
                actorType: 'admin',
                resourceType: 'identity.configuration',
                outcome: 'success',
                resourceId: $key,
                beforeState: ['value' => $meta['is_sensitive'] ? '[REDACTED]' : $oldValue],
                afterState: ['value' => $meta['is_sensitive'] ? '[REDACTED]' : $newValue],
                correlationId: request()?->header('X-Correlation-ID') ?? '',
                ipAddress: request()?->ip() ?? '',
                actorId: $adminId,
            ));
        }

        return [
            'key' => $key,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'updated_at' => $now->toIso8601String(),
            'updated_by' => $adminId,
        ];
    }

    /**
     * Check whether a key exists in the REGISTRY.
     */
    public function keyExists(string $key): bool
    {
        return array_key_exists($key, self::REGISTRY);
    }

    /**
     * Return the registry metadata for a single key, or null if not registered.
     *
     * @return array{description: string, data_type: string, ...}|null
     */
    public function getMeta(string $key): ?array
    {
        return self::REGISTRY[$key] ?? null;
    }

    // -------------------------------------------------------------------------
    // Private: audit action resolution
    // -------------------------------------------------------------------------

    private function resolveAuditAction(string $key): ?string
    {
        if (str_starts_with($key, 'auth.lockout.')) {
            return SecurityAuditActions::CONFIG_LOCKOUT_CHANGED;
        }

        // Future: rate-limit keys would emit CONFIG_RATE_LIMIT_CHANGED
        return null;
    }

    // -------------------------------------------------------------------------
    // Private: typed validation
    // -------------------------------------------------------------------------

    private function validateType(string $key, string $value, array $meta): void
    {
        match ($meta['data_type']) {
            'integer' => $this->assertInteger($key, $value, $meta),
            'boolean' => $this->assertBoolean($key, $value),
            'decimal' => $this->assertDecimal($key, $value, $meta),
            'string' => $this->assertString($key, $value, $meta),
            default => null,
        };
    }

    private function assertInteger(string $key, string $value, array $meta): void
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false) {
            throw ConfigurationConflictException::invalidValue($key, "expected an integer, got '{$value}'");
        }
        if (isset($meta['min']) && $int < $meta['min']) {
            throw ConfigurationConflictException::invalidValue(
                $key,
                "value {$int} is below the allowed minimum {$meta['min']}",
            );
        }
        if (isset($meta['max']) && $int > $meta['max']) {
            throw ConfigurationConflictException::invalidValue(
                $key,
                "value {$int} exceeds the allowed maximum {$meta['max']}",
            );
        }
    }

    private function assertBoolean(string $key, string $value): void
    {
        if (! in_array(strtolower($value), ['true', 'false', '1', '0'], true)) {
            throw ConfigurationConflictException::invalidValue($key, "expected 'true' or 'false'");
        }
    }

    private function assertDecimal(string $key, string $value, array $meta): void
    {
        $float = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($float === false) {
            throw ConfigurationConflictException::invalidValue($key, "expected a decimal number, got '{$value}'");
        }
        if (isset($meta['min']) && $float < $meta['min']) {
            throw ConfigurationConflictException::invalidValue(
                $key,
                "value {$float} is below the allowed minimum {$meta['min']}",
            );
        }
        if (isset($meta['max']) && $float > $meta['max']) {
            throw ConfigurationConflictException::invalidValue(
                $key,
                "value {$float} exceeds the allowed maximum {$meta['max']}",
            );
        }
    }

    private function assertString(string $key, string $value, array $meta): void
    {
        if (trim($value) === '') {
            throw ConfigurationConflictException::invalidValue($key, 'value must not be empty');
        }
        if (isset($meta['allowed_values']) && ! in_array($value, $meta['allowed_values'], true)) {
            $allowed = implode(', ', $meta['allowed_values']);
            throw ConfigurationConflictException::invalidValue(
                $key,
                "value '{$value}' is not in the allowed set: [{$allowed}]",
            );
        }
    }
}
