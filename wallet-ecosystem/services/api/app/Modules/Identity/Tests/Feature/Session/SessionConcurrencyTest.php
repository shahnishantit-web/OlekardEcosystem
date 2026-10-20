<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;

/**
 * Phase 8 Concurrency Tests â€” T094
 * Concurrent rotation of the same refresh token: exactly one succeeds, the other triggers reuse detection.
 */

beforeEach(function (): void {
    Redis::connection('identity')->flushdb();
});

function concSessionLogin(string $mobile, string $pin, string $deviceId): array
{
    $users    = app(UserRepositoryInterface::class);
    $credRepo = app(CredentialRepositoryInterface::class);
    $devRepo  = app(DeviceRepositoryInterface::class);

    $user = $users->create([
        'mobile_number' => $mobile,
        'status'        => 'active',
        'actor_type'    => 'CUSTOMER',
    ]);

    $credRepo->create([
        'user_id'         => $user->id,
        'credential_type' => 'pin',
        'credential_hash' => Hash::make($pin),
        'status'          => 'active',
    ]);

    $devRepo->create([
        'user_id'            => $user->id,
        'device_fingerprint' => hash('sha256', $deviceId),
        'status'             => 'trusted',
        'platform'           => 'android',
    ]);

    $response = test()->postJson('/v1/auth/login', [
        'identifier'         => $mobile,
        'credential_type'    => 'pin',
        'credential'         => $pin,
        'device_fingerprint' => $deviceId,
    ]);

    $response->assertStatus(200);

    return [
        'access_token'  => $response->json('data.access_token'),
        'refresh_token' => $response->json('data.refresh_token'),
        'session_id'    => $response->json('data.session_id'),
    ];
}

describe('Concurrent refresh rotation (spec Section 24.3)', function (): void {

    it('sequential duplicate rotation: second request returns 401', function (): void {
        $session = concSessionLogin('+26771030001', '123456', 'dev-cc-001');
        $token   = $session['refresh_token'];

        // First rotation succeeds
        $first = test()->postJson('/v1/auth/token/refresh', [
            'refresh_token' => $token,
        ]);
        $first->assertStatus(200);

        // Second rotation with same token is rejected (reuse detection)
        $second = test()->postJson('/v1/auth/token/refresh', [
            'refresh_token' => $token,
        ]);
        $second->assertStatus(401)
            ->assertJsonPath('error.code', 'IDENTITY_015');
    });

    it('token after successful rotation is usable for further rotations', function (): void {
        $session = concSessionLogin('+26771030002', '123456', 'dev-cc-002');

        $first = test()->postJson('/v1/auth/token/refresh', [
            'refresh_token' => $session['refresh_token'],
        ])->assertStatus(200);

        // The newly issued refresh token can itself be rotated
        test()->postJson('/v1/auth/token/refresh', [
            'refresh_token' => $first->json('data.refresh_token'),
        ])->assertStatus(200);
    });

    it('invalid refresh token returns IDENTITY_002', function (): void {
        test()->postJson('/v1/auth/token/refresh', [
            'refresh_token' => str_repeat('x', 43),
        ])->assertStatus(401)
            ->assertJsonPath('error.code', 'IDENTITY_002');
    });

});

