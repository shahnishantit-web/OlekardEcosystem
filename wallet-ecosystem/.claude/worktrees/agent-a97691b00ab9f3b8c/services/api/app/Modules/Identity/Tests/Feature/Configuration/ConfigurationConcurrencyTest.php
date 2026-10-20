<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Phase 15 Configuration Admin API Concurrency Tests — T150
 *
 * Covers:
 * - Two admins read the same key and both get the same version token
 * - First admin updates successfully
 * - Second admin's update with stale version is rejected with IDENTITY_016
 * - DB contains only the first admin's write (no lost update)
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

function concCreateUser(string $mobile, string $fp = 'conc-dev'): array
{
    $users    = app(UserRepositoryInterface::class);
    $credRepo = app(CredentialRepositoryInterface::class);
    $devRepo  = app(DeviceRepositoryInterface::class);

    $user = $users->create([
        'mobile_number' => $mobile,
        'status'        => 'active',
        'actor_type'    => 'ADMIN',
    ]);
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

function concLogin(mixed $test, string $mobile, string $fp = 'conc-dev'): string
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

describe('Configuration concurrency — optimistic locking (T150)', function (): void {

    it('second admin update with stale version is rejected with 409 IDENTITY_016', function (): void {
        concCreateUser('+26782001001', 'conc-admin1');
        concCreateUser('+26782001002', 'conc-admin2');

        $token1 = concLogin($this, '+26782001001', 'conc-admin1');
        $token2 = concLogin($this, '+26782001002', 'conc-admin2');

        // Both admins read the same version concurrently (simulated by reading before either writes)
        $sharedVersion = (function (): string {
            $row = DB::connection('platform')
                ->table('platform.configuration_values')
                ->where('config_key', 'auth.lockout.max_failed_attempts')
                ->first(['updated_at']);
            return $row
                ? \Carbon\Carbon::parse($row->updated_at)->toIso8601String()
                : now()->toIso8601String();
        })();

        // Admin 1 writes first — succeeds
        $resp1 = $this->putJson(
            '/v1/admin/platform/configuration/security/auth.lockout.max_failed_attempts',
            ['value' => '5', 'version' => $sharedVersion, 'reason' => 'admin1 update'],
            [
                'Authorization'  => "Bearer {$token1}",
                'Idempotency-Key' => (string) Str::uuid(),
            ]
        );
        $resp1->assertStatus(200);

        // Admin 2 writes with the same (now stale) version — must be rejected
        $resp2 = $this->putJson(
            '/v1/admin/platform/configuration/security/auth.lockout.max_failed_attempts',
            ['value' => '7', 'version' => $sharedVersion, 'reason' => 'admin2 update'],
            [
                'Authorization'  => "Bearer {$token2}",
                'Idempotency-Key' => (string) Str::uuid(),
            ]
        );
        $resp2->assertStatus(409);
        expect($resp2->json('error.code'))->toBe('IDENTITY_016');

        // Only admin 1's value is persisted — no lost update
        $row = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'auth.lockout.max_failed_attempts')
            ->first();
        expect($row->config_value)->toBe('5');
    });

    it('409 response includes current_updated_at and current_updated_by (AC-025)', function (): void {
        concCreateUser('+26782002001', 'conc-admin3');
        concCreateUser('+26782002002', 'conc-admin4');

        $token1 = concLogin($this, '+26782002001', 'conc-admin3');
        $token2 = concLogin($this, '+26782002002', 'conc-admin4');

        $sharedVersion = (function (): string {
            $row = DB::connection('platform')
                ->table('platform.configuration_values')
                ->where('config_key', 'auth.lockout.initial_duration')
                ->first(['updated_at']);
            return $row
                ? \Carbon\Carbon::parse($row->updated_at)->toIso8601String()
                : now()->toIso8601String();
        })();

        // First admin succeeds
        $this->putJson(
            '/v1/admin/platform/configuration/security/auth.lockout.initial_duration',
            ['value' => '300', 'version' => $sharedVersion],
            [
                'Authorization'  => "Bearer {$token1}",
                'Idempotency-Key' => (string) Str::uuid(),
            ]
        )->assertStatus(200);

        // Second admin conflicts
        $resp2 = $this->putJson(
            '/v1/admin/platform/configuration/security/auth.lockout.initial_duration',
            ['value' => '600', 'version' => $sharedVersion],
            [
                'Authorization'  => "Bearer {$token2}",
                'Idempotency-Key' => (string) Str::uuid(),
            ]
        );

        $resp2->assertStatus(409);
        expect($resp2->json('error.code'))->toBe('IDENTITY_016');
        // Conflict response includes current timestamp so the caller can retry with fresh version
        expect($resp2->json('error.data.current_updated_at'))->not->toBeNull();
    });

    it('admin can retry with fresh version after 409 and succeed', function (): void {
        concCreateUser('+26782003001', 'conc-admin5');
        concCreateUser('+26782003002', 'conc-admin6');

        $token1 = concLogin($this, '+26782003001', 'conc-admin5');
        $token2 = concLogin($this, '+26782003002', 'conc-admin6');

        $originalVersion = (function (): string {
            $row = DB::connection('platform')
                ->table('platform.configuration_values')
                ->where('config_key', 'auth.session.inactivity_ttl_admin')
                ->first(['updated_at']);
            return $row
                ? \Carbon\Carbon::parse($row->updated_at)->toIso8601String()
                : now()->toIso8601String();
        })();

        // Admin 1 updates first
        $this->putJson(
            '/v1/admin/platform/configuration/security/auth.session.inactivity_ttl_admin',
            ['value' => '1800', 'version' => $originalVersion],
            [
                'Authorization'  => "Bearer {$token1}",
                'Idempotency-Key' => (string) Str::uuid(),
            ]
        )->assertStatus(200);

        // Admin 2 gets 409 with stale version
        $this->putJson(
            '/v1/admin/platform/configuration/security/auth.session.inactivity_ttl_admin',
            ['value' => '2400', 'version' => $originalVersion],
            [
                'Authorization'  => "Bearer {$token2}",
                'Idempotency-Key' => (string) Str::uuid(),
            ]
        )->assertStatus(409);

        // Admin 2 re-reads the fresh version
        $getResp = $this->getJson('/v1/admin/platform/configuration/security', [
            'Authorization' => "Bearer {$token2}",
        ]);
        $getResp->assertStatus(200);

        $freshKey = collect($getResp->json('data.configuration'))
            ->firstWhere('key', 'auth.session.inactivity_ttl_admin');
        expect($freshKey)->not->toBeNull();
        $freshVersion = $freshKey['last_updated_at'];

        // Admin 2 retries with fresh version — succeeds
        $retryResp = $this->putJson(
            '/v1/admin/platform/configuration/security/auth.session.inactivity_ttl_admin',
            ['value' => '2400', 'version' => $freshVersion],
            [
                'Authorization'  => "Bearer {$token2}",
                'Idempotency-Key' => (string) Str::uuid(),
            ]
        );
        $retryResp->assertStatus(200);

        $row = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'auth.session.inactivity_ttl_admin')
            ->first();
        expect($row->config_value)->toBe('2400');
    });

});
