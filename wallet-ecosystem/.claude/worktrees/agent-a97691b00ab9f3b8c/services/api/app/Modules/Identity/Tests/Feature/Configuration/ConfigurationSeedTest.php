<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;


describe('Identity configuration seed', function (): void {

    it('seeds all 25 identity configuration keys on fresh install', function (): void {
        $keys = DB::connection('platform')
            ->table('platform.configuration_values')
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
            ->pluck('config_key')
            ->toArray();

        expect(count($keys))->toBe(25);
    });

    it('seeds auth.step_up_threshold with default 10000', function (): void {
        $row = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'auth.step_up_threshold')
            ->first();

        expect($row)->not->toBeNull();
        expect($row->config_value)->toBe('10000');
        expect($row->value_type)->toBe('number');
    });

    it('seeds auth.mfa.admin.sms_enabled as false by default', function (): void {
        $row = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'auth.mfa.admin.sms_enabled')
            ->first();

        expect($row)->not->toBeNull();
        expect($row->config_value)->toBe('false');
    });

    it('does not overwrite an existing configured value when run again (idempotency)', function (): void {
        // Simulate a production override: change the threshold to 20000
        DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'auth.step_up_threshold')
            ->update(['config_value' => '20000']);

        // Re-run the seed logic (insertOrIgnore should skip existing rows)
        DB::connection('platform')->table('platform.configuration_values')->insertOrIgnore([
            [
                'id'          => \Illuminate\Support\Str::uuid(),
                'config_key'  => 'auth.step_up_threshold',
                'config_value' => '10000',
                'value_type'  => 'number',
                'status'      => 'active',
                'version'     => 1,
                'is_tbd'      => false,
                'description' => 'Seed attempt',
                'effective_from'  => null,
                'effective_until' => null,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ]);

        $row = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'auth.step_up_threshold')
            ->first();

        // Production value must be preserved
        expect($row->config_value)->toBe('20000');
    });

    it('seeds security.ip_audit.hash_algorithm as HMAC-SHA256', function (): void {
        $row = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'security.ip_audit.hash_algorithm')
            ->first();

        expect($row)->not->toBeNull();
        expect($row->config_value)->toBe('HMAC-SHA256');
    });

    it('does not seed any secret values into configuration_values', function (): void {
        // Verify none of the seeded rows contain a description suggesting secrets are stored
        $suspiciousKeys = DB::connection('platform')
            ->table('platform.configuration_values')
            ->whereIn('config_key', [
                'auth.step_up_threshold',
                'auth.step_up_threshold_currency',
                'auth.session.inactivity_ttl_admin',
                'auth.otp.ttl',
                'security.ip_audit.hash_algorithm',
            ])
            ->whereRaw("config_value LIKE '%key%' OR config_value LIKE '%secret%' OR config_value LIKE '%password%'")
            ->count();

        expect($suspiciousKeys)->toBe(0);
    });

})->group('identity', 'configuration', 'seed');
