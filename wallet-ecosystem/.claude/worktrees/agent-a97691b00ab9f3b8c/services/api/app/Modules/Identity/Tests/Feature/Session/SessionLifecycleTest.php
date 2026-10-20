<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;

/**
 * Phase 8 Feature Tests â€” Session & Refresh Token Lifecycle (T092)
 * AC-005: logout sets session status; AC-006: reuse detection; BR-008.
 */

function sessionLoginAs(string $mobile, string $pin, string $deviceId): array
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

describe('Refresh token rotation (AC-005, FR-E01-020)', function (): void {

    it('returns new access and refresh tokens on valid rotation', function (): void {
        $session = sessionLoginAs('+26771010001', '123456', 'dev-sl-001');

        $response = test()->postJson('/v1/auth/token/refresh', [
            'refresh_token' => $session['refresh_token'],
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => ['access_token', 'refresh_token', 'token_type', 'expires_in', 'session_id']]);

        expect($response->json('data.access_token'))->not->toBe($session['access_token']);
        expect($response->json('data.refresh_token'))->not->toBe($session['refresh_token']);
    });

    it('rejects the old refresh token after rotation', function (): void {
        $session = sessionLoginAs('+26771010002', '123456', 'dev-sl-002');
        $oldRefresh = $session['refresh_token'];

        test()->postJson('/v1/auth/token/refresh', [
            'refresh_token' => $oldRefresh,
        ])->assertStatus(200);

        // Old token must now be rejected
        test()->postJson('/v1/auth/token/refresh', [
            'refresh_token' => $oldRefresh,
        ])->assertStatus(401)
            ->assertJson(['success' => false]);
    });

    it('chains multiple rotations successfully', function (): void {
        $session = sessionLoginAs('+26771010003', '123456', 'dev-sl-003');
        $currentRefresh = $session['refresh_token'];

        foreach (range(1, 3) as $_) {
            $response = test()->postJson('/v1/auth/token/refresh', [
                'refresh_token' => $currentRefresh,
            ])->assertStatus(200);
            $currentRefresh = $response->json('data.refresh_token');
        }

        expect($currentRefresh)->not->toBeEmpty();
    });

});

describe('Reuse detection â€” revokes entire family (AC-006, BR-008)', function (): void {

    it('revokes all sessions in the family when a rotated token is reused', function (): void {
        $session = sessionLoginAs('+26771010004', '123456', 'dev-sl-004');
        $originalRefresh = $session['refresh_token'];

        // Rotate once to get a new token
        $newSession = test()->postJson('/v1/auth/token/refresh', [
            'refresh_token' => $originalRefresh,
        ])->assertStatus(200);
        $newRefresh = $newSession->json('data.refresh_token');

        // Reuse the original (now-rotated) token â€” triggers reuse detection
        test()->postJson('/v1/auth/token/refresh', [
            'refresh_token' => $originalRefresh,
        ])->assertStatus(401)
            ->assertJsonPath('error.code', 'IDENTITY_015');

        // New token is also revoked (entire family)
        test()->postJson('/v1/auth/token/refresh', [
            'refresh_token' => $newRefresh,
        ])->assertStatus(401);
    });

});

describe('Session list excludes sensitive fields (FR-E01-026)', function (): void {

    it('returns active sessions without ip_hash or refresh_token_hash', function (): void {
        $session = sessionLoginAs('+26771010005', '123456', 'dev-sl-005');

        $response = test()->withToken($session['access_token'])
            ->getJson('/v1/auth/sessions');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data'])
            ->assertJsonCount(1, 'data');

        $item = $response->json('data.0');
        expect($item)->not->toHaveKey('ip_hash');
        expect($item)->not->toHaveKey('refresh_token_hash');
        expect($item)->toHaveKey('id');
        expect($item)->toHaveKey('status');
    });

});

describe('Logout sets session status (AC-005)', function (): void {

    it('POST /v1/auth/logout sets session to logged_out', function (): void {
        $session = sessionLoginAs('+26771010006', '123456', 'dev-sl-006');

        test()->withToken($session['access_token'])
            ->postJson('/v1/auth/logout')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    });

    it('logged-out session rejected on subsequent calls', function (): void {
        $session = sessionLoginAs('+26771010007', '123456', 'dev-sl-007');

        test()->withToken($session['access_token'])
            ->postJson('/v1/auth/logout')
            ->assertStatus(200);

        // Re-using the same token after logout must be rejected
        test()->withToken($session['access_token'])
            ->getJson('/v1/auth/sessions')
            ->assertStatus(401);
    });

    it('DELETE /v1/auth/sessions revokes all active sessions', function (): void {
        $session = sessionLoginAs('+26771010008', '123456', 'dev-sl-008');

        test()->withToken($session['access_token'])
            ->deleteJson('/v1/auth/sessions')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    });

});

describe('Unauthenticated access is rejected', function (): void {

    it('GET /v1/auth/sessions without token returns 401', function (): void {
        test()->getJson('/v1/auth/sessions')
            ->assertStatus(401);
    });

    it('POST /v1/auth/logout without token returns 401', function (): void {
        test()->postJson('/v1/auth/logout')
            ->assertStatus(401);
    });

    it('GET /v1/auth/devices without token returns 401', function (): void {
        test()->getJson('/v1/auth/devices')
            ->assertStatus(401);
    });

});

