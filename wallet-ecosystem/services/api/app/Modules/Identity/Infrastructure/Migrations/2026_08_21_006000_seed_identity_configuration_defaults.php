<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    protected $connection = 'platform';

    private function now(): string
    {
        return now()->toDateTimeString();
    }

    private function row(
        string $key,
        string $value,
        string $valueType,
        string $description,
    ): array {
        return [
            'id'          => (string) Str::uuid(),
            'config_key'  => $key,
            'config_value' => $value,
            'value_type'  => $valueType,
            'status'      => 'active',
            'version'     => 1,
            'is_tbd'      => false,
            'description' => $description,
            'effective_from'  => null,
            'effective_until' => null,
            'created_at'  => $this->now(),
            'updated_at'  => $this->now(),
        ];
    }

    public function up(): void
    {
        // insertOrIgnore = ON CONFLICT DO NOTHING; never overwrites existing values (BR-017; AC-021)
        DB::connection('platform')->table('platform.configuration_values')->insertOrIgnore([
            $this->row('auth.step_up_threshold', '10000', 'number',
                'Transaction amount (in threshold currency) above which step-up MFA is required (GAP-027 resolved; default 10000 BWP)'),
            $this->row('auth.step_up_threshold_currency', 'BWP', 'string',
                'Currency ISO code for the step-up threshold'),
            $this->row('auth.session.inactivity_ttl_admin', '900', 'number',
                'Admin session inactivity timeout in seconds (15 minutes)'),
            $this->row('auth.session.inactivity_ttl_customer', '1800', 'number',
                'Customer session inactivity timeout in seconds (30 minutes)'),
            $this->row('auth.session.inactivity_ttl_agent', '1800', 'number',
                'Agent session inactivity timeout in seconds (30 minutes)'),
            $this->row('auth.refresh_token.ttl', '2592000', 'number',
                'Refresh token lifetime in seconds (30 days)'),
            $this->row('auth.refresh_token.rotation_enabled', 'true', 'boolean',
                'Rotate refresh token on every successful refresh'),
            $this->row('auth.refresh_token.reuse_detection_enabled', 'true', 'boolean',
                'Revoke token family on refresh-token reuse detection'),
            $this->row('auth.otp.ttl', '300', 'number',
                'OTP validity window in seconds (5 minutes)'),
            $this->row('auth.otp.max_attempts', '5', 'number',
                'Maximum OTP verification attempts before challenge invalidation'),
            $this->row('auth.otp.resend_cooldown', '60', 'number',
                'Minimum seconds between OTP resend requests'),
            $this->row('auth.otp.max_sends_per_hour', '5', 'number',
                'Maximum OTP sends per account/channel per hour'),
            $this->row('auth.pin.length', '6', 'number',
                'Required PIN length in digits (platform minimum: 6)'),
            $this->row('auth.pin.reject_sequential', 'true', 'boolean',
                'Reject sequential PINs (e.g., 123456, 654321)'),
            $this->row('auth.pin.reject_repeated', 'true', 'boolean',
                'Reject repeated-digit PINs (e.g., 111111, 000000)'),
            $this->row('auth.pin.reject_common', 'true', 'boolean',
                'Reject known/common PIN values'),
            $this->row('auth.lockout.max_failed_attempts', '5', 'number',
                'Consecutive failures before account lockout'),
            $this->row('auth.lockout.initial_duration', '900', 'number',
                'Initial lockout duration in seconds (15 minutes)'),
            $this->row('auth.lockout.progressive_enabled', 'true', 'boolean',
                'Increase lockout duration for repeated lockout episodes'),
            $this->row('auth.mfa.admin.totp_enabled', 'true', 'boolean',
                'Allow TOTP MFA enrollment for Admin actors'),
            $this->row('auth.mfa.admin.fido2_enabled', 'true', 'boolean',
                'Allow FIDO2/WebAuthn MFA enrollment for Admin actors'),
            $this->row('auth.mfa.admin.sms_enabled', 'false', 'boolean',
                'Allow SMS MFA for Admin actors (disabled by default — weaker factor for privileged accounts)'),
            $this->row('auth.biometric.enabled', 'true', 'boolean',
                'Enable biometric authentication on supported devices'),
            $this->row('auth.otp.provider', 'configured', 'string',
                'OTP provider identifier; actual credentials stored as secrets, not in this table'),
            $this->row('security.ip_audit.hash_algorithm', 'HMAC-SHA256', 'string',
                'Algorithm for IP representation in audit records; secret stored in platform secret manager'),
        ]);
    }

    public function down(): void
    {
        // Seed rollback removes only rows seeded by this migration (version 1, status active)
        DB::connection('platform')->table('platform.configuration_values')
            ->whereIn('config_key', [
                'auth.step_up_threshold',
                'auth.step_up_threshold_currency',
                'auth.session.inactivity_ttl_admin',
                'auth.session.inactivity_ttl_customer',
                'auth.session.inactivity_ttl_agent',
                'auth.refresh_token.ttl',
                'auth.refresh_token.rotation_enabled',
                'auth.refresh_token.reuse_detection_enabled',
                'auth.otp.ttl',
                'auth.otp.max_attempts',
                'auth.otp.resend_cooldown',
                'auth.otp.max_sends_per_hour',
                'auth.pin.length',
                'auth.pin.reject_sequential',
                'auth.pin.reject_repeated',
                'auth.pin.reject_common',
                'auth.lockout.max_failed_attempts',
                'auth.lockout.initial_duration',
                'auth.lockout.progressive_enabled',
                'auth.mfa.admin.totp_enabled',
                'auth.mfa.admin.fido2_enabled',
                'auth.mfa.admin.sms_enabled',
                'auth.biometric.enabled',
                'auth.otp.provider',
                'security.ip_audit.hash_algorithm',
            ])
            ->where('version', 1)
            ->delete();
    }
};
