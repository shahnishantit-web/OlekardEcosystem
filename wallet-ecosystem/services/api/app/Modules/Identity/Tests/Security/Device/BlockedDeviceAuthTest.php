<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;

/**
 * T116 — Security test: blocked device prevents authentication.
 *
 * Verifies that a blocked device cannot be used to obtain tokens,
 * that the block check happens before token issuance,
 * and that an unblocked device on the same account CAN authenticate.
 */

beforeEach(function (): void {
    $this->userId    = (string) \Ramsey\Uuid\Uuid::uuid7();
    $this->blockedId = (string) \Ramsey\Uuid\Uuid::uuid7();
    $this->goodId    = (string) \Ramsey\Uuid\Uuid::uuid7();

    $this->blockedFp     = 'blocked-device-fingerprint';           // raw — sent during login
    $this->blockedFpHash = hash('sha256', 'blocked-device-fingerprint'); // stored in DB
    $this->goodFp        = 'good-device-fingerprint';              // raw — sent during login
    $this->goodFpHash    = hash('sha256', 'good-device-fingerprint'); // stored in DB

    \DB::connection('identity')->table('identity.users')->insert([
        'id'         => $this->userId,
        'email'      => 'blocked-auth@test.local',
        'mobile_number'      => '+26772500001',
        'status'     => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    \DB::connection('identity')->table('identity.credentials')->insert([
        'id'              => (string) \Ramsey\Uuid\Uuid::uuid7(),
        'user_id'         => $this->userId,
        'credential_type' => 'pin',
        'credential_hash' => Hash::make('123456'),
        'status'          => 'active',
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    // Blocked device
    \DB::connection('identity')->table('identity.devices')->insert([
        'id'                 => $this->blockedId,
        'user_id'            => $this->userId,
        'device_name'        => 'Blocked Phone',
        'platform'           => 'android',
        'device_fingerprint' => $this->blockedFpHash,
        'status'             => 'blocked',
        'blocked_at'         => now()->toIso8601String(),
        'blocked_reason'     => 'Security test',
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    // Good (trusted) device
    \DB::connection('identity')->table('identity.devices')->insert([
        'id'                 => $this->goodId,
        'user_id'            => $this->userId,
        'device_name'        => 'Trusted Phone',
        'platform'           => 'android',
        'device_fingerprint' => $this->goodFpHash,
        'status'             => 'trusted',
        'trusted_at'         => now()->toIso8601String(),
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    Redis::connection('identity')->flushdb();
});

describe('Blocked device authentication security', function (): void {

    it('login with a blocked device fingerprint returns 403 DEVICE_BLOCKED', function (): void {
        $response = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26772500001',
            'credential'         => '123456',
            'device_fingerprint' => $this->blockedFp,
        ]);

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('DEVICE_BLOCKED');
    });

    it('blocked device response does NOT include access_token or refresh_token', function (): void {
        $response = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26772500001',
            'credential'         => '123456',
            'device_fingerprint' => $this->blockedFp,
        ]);

        $body = $response->getContent();
        expect($body)->not->toContain('access_token');
        expect($body)->not->toContain('refresh_token');
    });

    it('login with a different trusted device from the same account succeeds', function (): void {
        $response = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26772500001',
            'credential'         => '123456',
            'device_fingerprint' => $this->goodFp,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['access_token', 'session_id']]);
    });

    it('block check occurs before token issuance (no token in 403 response)', function (): void {
        $response = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26772500001',
            'credential'         => '123456',
            'device_fingerprint' => $this->blockedFp,
        ]);

        $response->assertStatus(403);

        // Verify no session was created for the blocked device attempt
        $sessions = \DB::connection('identity')
            ->table('identity.sessions')
            ->where('device_id', $this->blockedId)
            ->count();

        expect($sessions)->toBe(0);
    });

})->group('identity', 'device', 'security');
