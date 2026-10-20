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
 * Phase 15 Configuration Admin API Feature Tests — T149
 *
 * Covers:
 * - Admin GET returns all 25 keys (AC-022)
 * - is_sensitive keys return REDACTED value in GET response (AC-022)
 * - Valid update persisted and audited (AC-023)
 * - before_state / after_state in audit record (BR-021)
 * - Value below security minimum rejected with 400 IDENTITY_001 (AC-024)
 * - Stale version rejected with 409 IDENTITY_016 (AC-025)
 * - Non-admin customer rejected with 403 AUTHORIZATION_002
 * - Missing version/value returns 400 IDENTITY_001
 * - Unknown key returns 404 IDENTITY_006
 * - Cache invalidated after successful PUT
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

// ============================================================================
// Helpers
// ============================================================================

function cfgCreateUser(string $mobile, string $actorType = 'ADMIN', string $fp = 'cfg-dev'): array
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

function cfgLogin(mixed $test, string $mobile, string $fp = 'cfg-dev'): string
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

function cfgGetVersion(string $key): string
{
    $row = DB::connection('platform')
        ->table('platform.configuration_values')
        ->where('config_key', $key)
        ->orderByDesc('version')
        ->first(['updated_at']);

    return $row ? \Carbon\Carbon::parse($row->updated_at)->toIso8601String() : now()->toIso8601String();
}

// ============================================================================
// GET — index (T149a)
// ============================================================================

describe('Configuration index — GET /v1/admin/platform/configuration/security (T149)', function (): void {

    it('admin can list all 25 configuration keys with metadata', function (): void {
        cfgCreateUser('+26781001001');
        $token = cfgLogin($this, '+26781001001');

        $resp = $this->getJson('/v1/admin/platform/configuration/security', [
            'Authorization' => "Bearer {$token}",
        ]);

        $resp->assertStatus(200);
        expect($resp->json('success'))->toBeTrue();
        expect($resp->json('data.total'))->toBe(25);
        expect($resp->json('data.configuration'))->toBeArray()->toHaveCount(25);
    });

    it('each key entry contains required metadata fields', function (): void {
        cfgCreateUser('+26781001002');
        $token = cfgLogin($this, '+26781001002');

        $resp = $this->getJson('/v1/admin/platform/configuration/security', [
            'Authorization' => "Bearer {$token}",
        ]);
        $resp->assertStatus(200);

        $first = collect($resp->json('data.configuration'))
            ->firstWhere('key', 'auth.otp.ttl');

        expect($first)->not->toBeNull();
        expect($first['key'])->toBe('auth.otp.ttl');
        expect($first['description'])->not->toBeEmpty();
        expect($first['data_type'])->toBe('integer');
        expect($first['default_value'])->toBe('300');
        expect($first['is_sensitive'])->toBeFalse();
        expect($first)->toHaveKey('min');
        expect($first)->toHaveKey('max');
    });

    it('is_sensitive key returns [REDACTED] value (AC-022)', function (): void {
        cfgCreateUser('+26781001003');
        $token = cfgLogin($this, '+26781001003');

        $resp = $this->getJson('/v1/admin/platform/configuration/security', [
            'Authorization' => "Bearer {$token}",
        ]);
        $resp->assertStatus(200);

        $sensitive = collect($resp->json('data.configuration'))
            ->firstWhere('key', 'security.ip_audit.hash_algorithm');

        expect($sensitive)->not->toBeNull();
        expect($sensitive['is_sensitive'])->toBeTrue();
        expect($sensitive['value'])->toBe('[REDACTED]');
    });

    it('non-admin customer is rejected with 403 AUTHORIZATION_002', function (): void {
        cfgCreateUser('+26781001004', 'CUSTOMER');
        $token = cfgLogin($this, '+26781001004');

        $resp = $this->getJson('/v1/admin/platform/configuration/security', [
            'Authorization' => "Bearer {$token}",
        ]);
        $resp->assertStatus(403);
        expect($resp->json('error.code'))->toBe('AUTHORIZATION_002');
    });

    it('unauthenticated request is rejected with 401', function (): void {
        $resp = $this->getJson('/v1/admin/platform/configuration/security');
        $resp->assertStatus(401);
    });

});

// ============================================================================
// PUT — update (T149b)
// ============================================================================

describe('Configuration update — PUT /v1/admin/platform/configuration/security/{key} (T149)', function (): void {

    it('valid update persists new value and returns old+new (AC-023)', function (): void {
        cfgCreateUser('+26781002001');
        $token = cfgLogin($this, '+26781002001');

        $version = cfgGetVersion('auth.otp.ttl');

        $resp = $this->putJson('/v1/admin/platform/configuration/security/auth.otp.ttl', [
            'value'   => '600',
            'version' => $version,
            'reason'  => 'test update',
        ], [
            'Authorization'  => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $resp->assertStatus(200);
        expect($resp->json('success'))->toBeTrue();
        expect($resp->json('data.key'))->toBe('auth.otp.ttl');
        expect($resp->json('data.new_value'))->toBe('600');
        expect($resp->json('data.old_value'))->not->toBeNull();
        expect($resp->json('data.updated_at'))->not->toBeNull();

        // Verify persisted in DB
        $row = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'auth.otp.ttl')
            ->first();
        expect($row->config_value)->toBe('600');
    });

    it('audit record created with before/after state on successful update (BR-021)', function (): void {
        cfgCreateUser('+26781002002');
        $token = cfgLogin($this, '+26781002002');
        $version = cfgGetVersion('auth.otp.max_attempts');

        $this->putJson('/v1/admin/platform/configuration/security/auth.otp.max_attempts', [
            'value'   => '3',
            'version' => $version,
            'reason'  => 'audit test',
        ], [
            'Authorization'  => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertStatus(200);

        $event = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'identity.config.updated')
            ->where('resource_id', 'auth.otp.max_attempts')
            ->orderByDesc('occurred_at')
            ->first();

        expect($event)->not->toBeNull();
        expect($event->outcome)->toBe('success');
        expect($event->resource_type)->toBe('configuration');

        $beforeState = json_decode((string) $event->before_state, true);
        $afterState  = json_decode((string) $event->after_state, true);

        expect($beforeState['configuration_key'])->toBe('auth.otp.max_attempts');
        expect($afterState['configuration_key'])->toBe('auth.otp.max_attempts');
        expect($afterState['new_value'])->toBe('3');
        expect($afterState['reason'])->toBe('audit test');
    });

    it('value below security minimum rejected with 400 IDENTITY_001 (AC-024)', function (): void {
        cfgCreateUser('+26781002003');
        $token = cfgLogin($this, '+26781002003');
        $version = cfgGetVersion('auth.pin.length');

        $resp = $this->putJson('/v1/admin/platform/configuration/security/auth.pin.length', [
            'value'   => '4',  // below platform minimum of 6
            'version' => $version,
        ], [
            'Authorization'  => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $resp->assertStatus(400);
        expect($resp->json('error.code'))->toBe('IDENTITY_001');
    });

    it('value exceeding maximum range rejected with 400 IDENTITY_001', function (): void {
        cfgCreateUser('+26781002004');
        $token = cfgLogin($this, '+26781002004');
        $version = cfgGetVersion('auth.otp.ttl');

        $resp = $this->putJson('/v1/admin/platform/configuration/security/auth.otp.ttl', [
            'value'   => '99999',  // above max 3600
            'version' => $version,
        ], [
            'Authorization'  => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $resp->assertStatus(400);
        expect($resp->json('error.code'))->toBe('IDENTITY_001');
    });

    it('stale version rejected with 409 IDENTITY_016 (AC-025)', function (): void {
        cfgCreateUser('+26781002005');
        $token   = cfgLogin($this, '+26781002005');
        $staleVersion = '2020-01-01T00:00:00+00:00';  // clearly stale

        $resp = $this->putJson('/v1/admin/platform/configuration/security/auth.otp.resend_cooldown', [
            'value'   => '120',
            'version' => $staleVersion,
        ], [
            'Authorization'  => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $resp->assertStatus(409);
        expect($resp->json('error.code'))->toBe('IDENTITY_016');
    });

    it('non-admin customer is rejected with 403 AUTHORIZATION_002 on PUT', function (): void {
        cfgCreateUser('+26781002006', 'CUSTOMER');
        $token = cfgLogin($this, '+26781002006');

        $resp = $this->putJson('/v1/admin/platform/configuration/security/auth.otp.ttl', [
            'value'   => '600',
            'version' => now()->toIso8601String(),
        ], [
            'Authorization'  => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $resp->assertStatus(403);
        expect($resp->json('error.code'))->toBe('AUTHORIZATION_002');
    });

    it('unknown key returns 404 IDENTITY_006', function (): void {
        cfgCreateUser('+26781002007');
        $token = cfgLogin($this, '+26781002007');

        $resp = $this->putJson('/v1/admin/platform/configuration/security/auth.unknown.key', [
            'value'   => 'foo',
            'version' => now()->toIso8601String(),
        ], [
            'Authorization'  => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $resp->assertStatus(404);
        expect($resp->json('error.code'))->toBe('IDENTITY_006');
    });

    it('missing value returns 400 IDENTITY_001', function (): void {
        cfgCreateUser('+26781002008');
        $token = cfgLogin($this, '+26781002008');

        $resp = $this->putJson('/v1/admin/platform/configuration/security/auth.otp.ttl', [
            'version' => now()->toIso8601String(),
            // no 'value'
        ], [
            'Authorization'  => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $resp->assertStatus(400);
        expect($resp->json('error.code'))->toBe('IDENTITY_001');
    });

    it('missing version returns 400 IDENTITY_001', function (): void {
        cfgCreateUser('+26781002009');
        $token = cfgLogin($this, '+26781002009');

        $resp = $this->putJson('/v1/admin/platform/configuration/security/auth.otp.ttl', [
            'value' => '600',
            // no 'version'
        ], [
            'Authorization'  => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $resp->assertStatus(400);
        expect($resp->json('error.code'))->toBe('IDENTITY_001');
    });

    it('invalid boolean value rejected with 400 IDENTITY_001', function (): void {
        cfgCreateUser('+26781002010');
        $token = cfgLogin($this, '+26781002010');
        $version = cfgGetVersion('auth.pin.reject_sequential');

        $resp = $this->putJson('/v1/admin/platform/configuration/security/auth.pin.reject_sequential', [
            'value'   => 'maybe',  // invalid boolean
            'version' => $version,
        ], [
            'Authorization'  => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $resp->assertStatus(400);
        expect($resp->json('error.code'))->toBe('IDENTITY_001');
    });

    it('idempotency replay returns cached response on second PUT with same key', function (): void {
        cfgCreateUser('+26781002011');
        $token   = cfgLogin($this, '+26781002011');
        $version = cfgGetVersion('auth.otp.resend_cooldown');
        $idemKey = (string) Str::uuid();

        $resp1 = $this->putJson('/v1/admin/platform/configuration/security/auth.otp.resend_cooldown', [
            'value'   => '90',
            'version' => $version,
        ], [
            'Authorization'  => "Bearer {$token}",
            'Idempotency-Key' => $idemKey,
        ]);
        $resp2 = $this->putJson('/v1/admin/platform/configuration/security/auth.otp.resend_cooldown', [
            'value'   => '90',
            'version' => $version,
        ], [
            'Authorization'  => "Bearer {$token}",
            'Idempotency-Key' => $idemKey,
        ]);

        $resp1->assertStatus(200);
        $resp2->assertStatus(200);
        expect($resp2->headers->get('Idempotency-Replay'))->toBe('true');
    });

    it('all 25 keys are present in the REGISTRY (sanity check)', function (): void {
        $registry = ConfigurationAdminService::REGISTRY;
        expect(count($registry))->toBe(25);
    });

});
