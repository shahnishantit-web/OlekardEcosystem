<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Services\ConfigurationAdminService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Phase 15 Configuration Admin API Security Tests — T151
 *
 * Covers:
 * - Sensitive keys never expose real values in GET or PUT responses
 * - Sensitive values never appear in audit before/after state
 * - Config endpoints are inaccessible without proper Bearer JWT
 * - Non-ADMIN actor types get 403 AUTHORIZATION_002 (RequireActorTypeMiddleware)
 * - Security minimum enforced for each key that has a minimum
 * - is_sensitive flag consistent between REGISTRY and API response
 */

beforeEach(function (): void {
    $this->app->bind(OtpDeliveryContract::class, fn () => new class implements OtpDeliveryContract {
        public function deliver(string $d, string $otp, string $ch, string $c): OtpDeliveryResult
        {
            return new OtpDeliveryResult(true, 'stub', null);
        }
    });
    Redis::connection('identity')->flushdb();
});

function secCreateUser(string $mobile, string $actorType = 'ADMIN', string $fp = 'sec-dev'): array
{
    $users    = app(UserRepositoryInterface::class);
    $credRepo = app(CredentialRepositoryInterface::class);
    $devRepo  = app(DeviceRepositoryInterface::class);

    $user = $users->create([
        'mobile_number' => $mobile,
        'status'        => 'active',
        'actor_type'    => $actorType,
    ]);

    if ($actorType === 'ADMIN') {
        $adminRole = DB::connection('identity')->table('identity.roles')
            ->where('slug', 'admin')->first(['id']);
        if ($adminRole) {
            DB::connection('identity')->table('identity.users')
                ->where('id', $user->id)
                ->update(['default_role_id' => $adminRole->id]);
        }
    }

    $credRepo->create([
        'user_id'         => $user->id,
        'credential_type' => 'pin',
        'credential_hash' => Hash::make('999888'),
        'status'          => 'active',
    ]);
    $devRepo->create([
        'user_id'            => $user->id,
        'device_fingerprint' => hash('sha256', $fp),
        'status'             => 'trusted',
        'platform'           => 'android',
    ]);

    return ['user' => $user, 'fingerprint' => $fp];
}

function secLogin(mixed $test, string $mobile, string $fp = 'sec-dev'): string
{
    $resp = $test->postJson('/v1/auth/login', [
        'credential_type'    => 'pin',
        'identifier'         => $mobile,
        'credential'         => '999888',
        'device_fingerprint' => $fp,
    ]);
    $resp->assertStatus(200);
    return (string) $resp->json('data.access_token');
}

function secGetVersion(string $key): string
{
    $row = DB::connection('platform')
        ->table('platform.configuration_values')
        ->where('config_key', $key)
        ->first(['updated_at']);
    return $row
        ? \Carbon\Carbon::parse($row->updated_at)->toIso8601String()
        : now()->toIso8601String();
}

// ============================================================================
// Sensitive key masking (AC-022)
// ============================================================================

describe('Sensitive key masking — no secret exposure (T151)', function (): void {

    it('sensitive key GET response never contains actual value', function (): void {
        secCreateUser('+26783001001');
        $token = secLogin($this, '+26783001001');

        $resp = $this->getJson('/v1/admin/platform/configuration/security', [
            'Authorization' => "Bearer {$token}",
        ]);
        $resp->assertStatus(200);

        $sensitive = collect($resp->json('data.configuration'))
            ->firstWhere('key', 'security.ip_audit.hash_algorithm');

        expect($sensitive['value'])->toBe('[REDACTED]');
        expect($sensitive['is_sensitive'])->toBeTrue();
        // The raw algo value must not leak
        expect($sensitive['value'])->not->toContain('HMAC');
        expect($sensitive['value'])->not->toContain('SHA');
    });

    it('sensitive key PUT response never exposes old_value or new_value', function (): void {
        secCreateUser('+26783001002');
        $token = secLogin($this, '+26783001002');
        $version = secGetVersion('security.ip_audit.hash_algorithm');

        $resp = $this->putJson(
            '/v1/admin/platform/configuration/security/security.ip_audit.hash_algorithm',
            ['value' => 'HMAC-SHA256', 'version' => $version],
            [
                'Authorization'  => "Bearer {$token}",
                'Idempotency-Key' => (string) Str::uuid(),
            ]
        );

        // Whether allowed or rejected, neither old nor new value is exposed
        if ($resp->status() === 200) {
            expect($resp->json('data.old_value'))->toBe('[REDACTED]');
            expect($resp->json('data.new_value'))->toBe('[REDACTED]');
        }
        // If rejected (e.g., value not in allowed list) — 400 is fine too; just no raw value leak
        expect((string) $resp->content())->not->toContain('HMAC-SHA256');
    });

    it('audit record for sensitive key update does not expose real value', function (): void {
        secCreateUser('+26783001003');
        $token = secLogin($this, '+26783001003');
        $version = secGetVersion('security.ip_audit.hash_algorithm');

        $this->putJson(
            '/v1/admin/platform/configuration/security/security.ip_audit.hash_algorithm',
            ['value' => 'HMAC-SHA256', 'version' => $version, 'reason' => 'security test'],
            [
                'Authorization'  => "Bearer {$token}",
                'Idempotency-Key' => (string) Str::uuid(),
            ]
        );

        $event = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'identity.config.updated')
            ->where('resource_id', 'security.ip_audit.hash_algorithm')
            ->orderByDesc('occurred_at')
            ->first();

        if ($event) {
            $before = json_decode((string) $event->before_state, true);
            $after  = json_decode((string) $event->after_state, true);

            expect((string) ($before['old_value'] ?? ''))->toBe('[REDACTED]');
            expect((string) ($after['new_value'] ?? ''))->toBe('[REDACTED]');

            $rawPayload = (string) $event->before_state . (string) $event->after_state;
            expect($rawPayload)->not->toContain('HMAC-SHA256');
        }
    });

    it('is_sensitive=true flag in REGISTRY matches API response flag', function (): void {
        secCreateUser('+26783001004');
        $token = secLogin($this, '+26783001004');

        $resp = $this->getJson('/v1/admin/platform/configuration/security', [
            'Authorization' => "Bearer {$token}",
        ]);
        $resp->assertStatus(200);

        $config = collect($resp->json('data.configuration'));

        foreach (ConfigurationAdminService::REGISTRY as $key => $meta) {
            $entry = $config->firstWhere('key', $key);
            expect($entry)->not->toBeNull("Key {$key} missing from API response");
            expect($entry['is_sensitive'])->toBe($meta['is_sensitive'],
                "is_sensitive mismatch for key {$key}: REGISTRY={$meta['is_sensitive']} API={$entry['is_sensitive']}");

            if ($meta['is_sensitive']) {
                expect($entry['value'])->toBe('[REDACTED]',
                    "Sensitive key {$key} must have [REDACTED] value, got: {$entry['value']}");
            }
        }
    });

});

// ============================================================================
// Authorization boundary (ADMIN-only access)
// ============================================================================

describe('Authorization boundary — config endpoints require ADMIN (T151)', function (): void {

    it('GET returns 401 for completely unauthenticated request', function (): void {
        $resp = $this->getJson('/v1/admin/platform/configuration/security');
        $resp->assertStatus(401);
    });

    it('PUT returns 401 for completely unauthenticated request', function (): void {
        $resp = $this->putJson(
            '/v1/admin/platform/configuration/security/auth.otp.ttl',
            ['value' => '600', 'version' => now()->toIso8601String()]
        );
        $resp->assertStatus(401);
    });

    it('CUSTOMER actor cannot GET configuration (403 AUTHORIZATION_002)', function (): void {
        secCreateUser('+26783002001', 'CUSTOMER');
        $token = secLogin($this, '+26783002001');

        $resp = $this->getJson('/v1/admin/platform/configuration/security', [
            'Authorization' => "Bearer {$token}",
        ]);
        $resp->assertStatus(403);
        expect($resp->json('error.code'))->toBe('AUTHORIZATION_002');
    });

    it('AGENT actor cannot GET configuration (403 AUTHORIZATION_002)', function (): void {
        secCreateUser('+26783002002', 'AGENT');
        $token = secLogin($this, '+26783002002');

        $resp = $this->getJson('/v1/admin/platform/configuration/security', [
            'Authorization' => "Bearer {$token}",
        ]);
        $resp->assertStatus(403);
        expect($resp->json('error.code'))->toBe('AUTHORIZATION_002');
    });

    it('CUSTOMER actor cannot PUT configuration (403 AUTHORIZATION_002)', function (): void {
        secCreateUser('+26783002003', 'CUSTOMER');
        $token = secLogin($this, '+26783002003');

        $resp = $this->putJson(
            '/v1/admin/platform/configuration/security/auth.otp.ttl',
            ['value' => '600', 'version' => now()->toIso8601String()],
            [
                'Authorization'  => "Bearer {$token}",
                'Idempotency-Key' => (string) Str::uuid(),
            ]
        );
        $resp->assertStatus(403);
        expect($resp->json('error.code'))->toBe('AUTHORIZATION_002');
    });

    it('AGENT actor cannot PUT configuration (403 AUTHORIZATION_002)', function (): void {
        secCreateUser('+26783002004', 'AGENT');
        $token = secLogin($this, '+26783002004');

        $resp = $this->putJson(
            '/v1/admin/platform/configuration/security/auth.otp.ttl',
            ['value' => '600', 'version' => now()->toIso8601String()],
            [
                'Authorization'  => "Bearer {$token}",
                'Idempotency-Key' => (string) Str::uuid(),
            ]
        );
        $resp->assertStatus(403);
        expect($resp->json('error.code'))->toBe('AUTHORIZATION_002');
    });

    it('tampered/invalid Bearer JWT is rejected with 401', function (): void {
        $resp = $this->getJson('/v1/admin/platform/configuration/security', [
            'Authorization' => 'Bearer not.a.jwt',
        ]);
        $resp->assertStatus(401);
    });

});

// ============================================================================
// Security minimums (per contracts/configuration-api.md AC-024)
// ============================================================================

describe('Security minimum enforcement per key (T151)', function (): void {

    it('auth.otp.ttl: value below minimum 60 is rejected', function (): void {
        secCreateUser('+26783003001');
        $token = secLogin($this, '+26783003001');

        $resp = $this->putJson(
            '/v1/admin/platform/configuration/security/auth.otp.ttl',
            ['value' => '30', 'version' => secGetVersion('auth.otp.ttl')],
            ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) Str::uuid()]
        );
        $resp->assertStatus(400);
        expect($resp->json('error.code'))->toBe('IDENTITY_001');
    });

    it('auth.otp.max_attempts: value below minimum 1 is rejected', function (): void {
        secCreateUser('+26783003002');
        $token = secLogin($this, '+26783003002');

        $resp = $this->putJson(
            '/v1/admin/platform/configuration/security/auth.otp.max_attempts',
            ['value' => '0', 'version' => secGetVersion('auth.otp.max_attempts')],
            ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) Str::uuid()]
        );
        $resp->assertStatus(400);
        expect($resp->json('error.code'))->toBe('IDENTITY_001');
    });

    it('auth.pin.length: value below minimum 6 is rejected', function (): void {
        secCreateUser('+26783003003');
        $token = secLogin($this, '+26783003003');

        $resp = $this->putJson(
            '/v1/admin/platform/configuration/security/auth.pin.length',
            ['value' => '4', 'version' => secGetVersion('auth.pin.length')],
            ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) Str::uuid()]
        );
        $resp->assertStatus(400);
        expect($resp->json('error.code'))->toBe('IDENTITY_001');
    });

    it('auth.lockout.max_failed_attempts: value below minimum 1 is rejected', function (): void {
        secCreateUser('+26783003004');
        $token = secLogin($this, '+26783003004');

        $resp = $this->putJson(
            '/v1/admin/platform/configuration/security/auth.lockout.max_failed_attempts',
            ['value' => '0', 'version' => secGetVersion('auth.lockout.max_failed_attempts')],
            ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) Str::uuid()]
        );
        $resp->assertStatus(400);
        expect($resp->json('error.code'))->toBe('IDENTITY_001');
    });

    it('auth.lockout.initial_duration: value below minimum 60 is rejected', function (): void {
        secCreateUser('+26783003005');
        $token = secLogin($this, '+26783003005');

        $resp = $this->putJson(
            '/v1/admin/platform/configuration/security/auth.lockout.initial_duration',
            ['value' => '30', 'version' => secGetVersion('auth.lockout.initial_duration')],
            ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) Str::uuid()]
        );
        $resp->assertStatus(400);
        expect($resp->json('error.code'))->toBe('IDENTITY_001');
    });

    it('auth.session.inactivity_ttl_admin: value below minimum 300 is rejected', function (): void {
        secCreateUser('+26783003006');
        $token = secLogin($this, '+26783003006');

        $resp = $this->putJson(
            '/v1/admin/platform/configuration/security/auth.session.inactivity_ttl_admin',
            ['value' => '60', 'version' => secGetVersion('auth.session.inactivity_ttl_admin')],
            ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) Str::uuid()]
        );
        $resp->assertStatus(400);
        expect($resp->json('error.code'))->toBe('IDENTITY_001');
    });

    it('auth.refresh_token.ttl: value below minimum 3600 is rejected', function (): void {
        secCreateUser('+26783003007');
        $token = secLogin($this, '+26783003007');

        $resp = $this->putJson(
            '/v1/admin/platform/configuration/security/auth.refresh_token.ttl',
            ['value' => '1800', 'version' => secGetVersion('auth.refresh_token.ttl')],
            ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) Str::uuid()]
        );
        $resp->assertStatus(400);
        expect($resp->json('error.code'))->toBe('IDENTITY_001');
    });

    it('auth.otp.provider: disallowed value is rejected', function (): void {
        secCreateUser('+26783003008');
        $token = secLogin($this, '+26783003008');

        $resp = $this->putJson(
            '/v1/admin/platform/configuration/security/auth.otp.provider',
            ['value' => 'email', 'version' => secGetVersion('auth.otp.provider')],
            ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) Str::uuid()]
        );
        $resp->assertStatus(400);
        expect($resp->json('error.code'))->toBe('IDENTITY_001');
    });

    it('security.ip_audit.hash_algorithm: disallowed value is rejected', function (): void {
        secCreateUser('+26783003009');
        $token = secLogin($this, '+26783003009');

        $resp = $this->putJson(
            '/v1/admin/platform/configuration/security/security.ip_audit.hash_algorithm',
            ['value' => 'MD5', 'version' => secGetVersion('security.ip_audit.hash_algorithm')],
            ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) Str::uuid()]
        );
        $resp->assertStatus(400);
        expect($resp->json('error.code'))->toBe('IDENTITY_001');
    });

    it('step_up_threshold accepts decimal value (AC-026 — step-up runtime configurable)', function (): void {
        secCreateUser('+26783003010');
        $token = secLogin($this, '+26783003010');

        $resp = $this->putJson(
            '/v1/admin/platform/configuration/security/auth.step_up_threshold',
            ['value' => '5000.00', 'version' => secGetVersion('auth.step_up_threshold')],
            ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) Str::uuid()]
        );
        // Decimal with value >= 0 should be accepted
        $resp->assertStatus(200);
        expect($resp->json('data.new_value'))->toBe('5000.00');
    });

    it('step_up_threshold rejects negative value', function (): void {
        secCreateUser('+26783003011');
        $token = secLogin($this, '+26783003011');

        $resp = $this->putJson(
            '/v1/admin/platform/configuration/security/auth.step_up_threshold',
            ['value' => '-1', 'version' => secGetVersion('auth.step_up_threshold')],
            ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) Str::uuid()]
        );
        $resp->assertStatus(400);
        expect($resp->json('error.code'))->toBe('IDENTITY_001');
    });

});
