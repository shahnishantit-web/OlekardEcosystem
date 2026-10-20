<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Infrastructure\Cache\IdentityRedisKeys;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;

/**
 * Phase 8 Security Tests â€” Session revocation, Redis cache, reuse detection (T093)
 * BR-008: refresh token reuse triggers Identity.Session.AllTerminated and revokes family.
 */

function secSessionLogin(string $mobile, string $pin, string $deviceId): array
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
        'user'          => $user,
        'access_token'  => $response->json('data.access_token'),
        'refresh_token' => $response->json('data.refresh_token'),
        'session_id'    => $response->json('data.session_id'),
    ];
}

beforeEach(function (): void {
    Redis::connection('identity')->flushdb();
});

describe('Redis revocation cache provides fast session rejection (FR-E01-022)', function (): void {

    it('session rejected when Redis revocation key is set', function (): void {
        $session = secSessionLogin('+26771020001', '123456', 'dev-ss-001');

        // Manually poison the Redis revocation cache
        Redis::connection('identity')->setex(
            IdentityRedisKeys::revokedSession($session['session_id']),
            3600,
            '1',
        );

        test()->withToken($session['access_token'])
            ->getJson('/v1/auth/sessions')
            ->assertStatus(401);
    });

    it('logout sets Redis revocation key for the session', function (): void {
        $session = secSessionLogin('+26771020002', '123456', 'dev-ss-002');
        $sessionId = $session['session_id'];

        test()->withToken($session['access_token'])
            ->postJson('/v1/auth/logout')
            ->assertStatus(200);

        // After logout the revocation key must exist in Redis
        $exists = Redis::connection('identity')->exists(
            IdentityRedisKeys::revokedSession($sessionId),
        );
        expect($exists)->toBeGreaterThan(0);
    });

});

describe('Refresh token reuse detection revokes entire family (BR-008)', function (): void {

    it('presenting a rotated token returns IDENTITY_015', function (): void {
        $session = secSessionLogin('+26771020003', '123456', 'dev-ss-003');
        $original = $session['refresh_token'];

        // Rotate once
        test()->postJson('/v1/auth/token/refresh', [
            'refresh_token' => $original,
        ])->assertStatus(200);

        // Re-present the original (now revoked) token
        test()->postJson('/v1/auth/token/refresh', [
            'refresh_token' => $original,
        ])->assertStatus(401)
            ->assertJsonPath('error.code', 'IDENTITY_015');
    });

    it('all sessions in the family are blocked after reuse detection', function (): void {
        $session = secSessionLogin('+26771020004', '123456', 'dev-ss-004');
        $original = $session['refresh_token'];

        // Rotate to get a second token
        $rotated = test()->postJson('/v1/auth/token/refresh', [
            'refresh_token' => $original,
        ])->json('data.refresh_token');

        // Trigger reuse detection with the old token
        test()->postJson('/v1/auth/token/refresh', [
            'refresh_token' => $original,
        ])->assertStatus(401)
            ->assertJsonPath('error.code', 'IDENTITY_015');

        // The second token must also be invalidated
        test()->postJson('/v1/auth/token/refresh', [
            'refresh_token' => $rotated,
        ])->assertStatus(401);
    });

});

describe('Device ownership checks (FR-E01-027)', function (): void {

    it('cannot revoke a device belonging to another user', function (): void {
        $sessionA = secSessionLogin('+26771020005', '123456', 'dev-ss-005');
        $sessionB = secSessionLogin('+26771020006', '123456', 'dev-ss-006');

        // Register a device for user B
        $regResponse = test()->withToken($sessionB['access_token'])
            ->postJson('/v1/auth/devices/register', [
                'device_fingerprint' => 'dev-ss-006-b',
                'device_name'        => 'Device B',
                'platform'           => 'ios',
            ]);
        $regResponse->assertStatus(201);
        $deviceId = $regResponse->json('data.id');

        // User A tries to revoke user B's device
        test()->withToken($sessionA['access_token'])
            ->deleteJson("/v1/auth/devices/{$deviceId}")
            ->assertStatus(404);
    });

    it('cannot list sessions of another user', function (): void {
        $sessionA = secSessionLogin('+26771020007', '123456', 'dev-ss-007');
        $sessionB = secSessionLogin('+26771020008', '123456', 'dev-ss-008');

        // User A lists their sessions â€” must not include user B's session
        $response = test()->withToken($sessionA['access_token'])
            ->getJson('/v1/auth/sessions');

        $response->assertStatus(200);
        $sessionIds = array_column($response->json('data'), 'id');

        expect($sessionIds)->not->toContain($sessionB['session_id']);
    });

});

describe('Invalid tokens are rejected', function (): void {

    it('expired Bearer token returns 401', function (): void {
        $header  = rtrim(strtr(base64_encode(json_encode(['alg' => 'ES256', 'typ' => 'JWT', 'kid' => 'test'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(['sub' => 'u1', 'exp' => time() - 10, 'iat' => time() - 20])), '+/', '-_'), '=');
        $fake    = "{$header}.{$payload}.fakesig";

        test()->withToken($fake)
            ->getJson('/v1/auth/sessions')
            ->assertStatus(401);
    });

    it('malformed token returns 401', function (): void {
        test()->withToken('not.a.jwt')
            ->getJson('/v1/auth/sessions')
            ->assertStatus(401);
    });

});

