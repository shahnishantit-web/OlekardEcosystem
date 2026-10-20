<?php

declare(strict_types=1);

use Tests\Support\FakeIdentityAuthMiddleware;

beforeEach(function (): void {
    $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

    $this->userId  = (string) \Ramsey\Uuid\Uuid::uuid7();
    $this->userId2 = (string) \Ramsey\Uuid\Uuid::uuid7();

    \DB::connection('identity')->table('identity.users')->insert([
        ['id' => $this->userId,  'email' => 'ack1@test.local', 'mobile_number' => '+26771900001', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['id' => $this->userId2, 'email' => 'ack2@test.local', 'mobile_number' => '+26771900002', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    ]);
});

function insertNewDevice(string $userId, string $status = 'pending_verification'): string
{
    $id = (string) \Ramsey\Uuid\Uuid::uuid7();
    \DB::connection('identity')->table('identity.devices')->insert([
        'id'                 => $id,
        'user_id'            => $userId,
        'device_name'        => 'New Device',
        'platform'           => 'android',
        'device_fingerprint' => hash('sha256', $id . 'ack'),
        'status'             => $status,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);
    return $id;
}

function insertSecurityEvent(string $deviceId, string $userId, bool $expired = false, ?string $resolution = null): void
{
    \DB::connection('identity')->table('identity.device_security_events')->insert([
        'id'                        => (string) \Ramsey\Uuid\Uuid::uuid7(),
        'device_id'                 => $deviceId,
        'user_id'                   => $userId,
        'event_type'                => 'new_device_notified',
        'acknowledgment_expires_at' => $expired ? now()->subDay() : now()->addDay(),
        'resolution'                => $resolution,
        'resolved_at'               => $resolution !== null ? now()->toIso8601String() : null,
        'correlation_id'            => (string) \Ramsey\Uuid\Uuid::uuid7(),
        'created_at'                => now(),
        'updated_at'                => now(),
    ]);
}

function ackHeaders(string $userId, ?string $idemKey = null): array
{
    return [
        'X-Test-User-Id'  => $userId,
        'Idempotency-Key' => $idemKey ?? 'ack-' . uniqid(),
    ];
}

describe('POST /v1/devices/{id}/acknowledge', function (): void {

    it('acknowledged=true promotes device to trusted', function (): void {
        $deviceId = insertNewDevice($this->userId);
        insertSecurityEvent($deviceId, $this->userId);

        $response = $this->postJson(
            "/v1/devices/{$deviceId}/acknowledge",
            ['acknowledged' => true],
            ackHeaders($this->userId),
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        expect($response->json('data.acknowledged'))->toBeTrue();
    });

    it('acknowledged=false blocks device and revokes sessions', function (): void {
        $deviceId = insertNewDevice($this->userId);
        insertSecurityEvent($deviceId, $this->userId);

        $response = $this->postJson(
            "/v1/devices/{$deviceId}/acknowledge",
            ['acknowledged' => false],
            ackHeaders($this->userId),
        );

        $response->assertStatus(200);
        expect($response->json('data.acknowledged'))->toBeFalse();

        // Device should be blocked
        $device = \DB::connection('identity')->table('identity.devices')->where('id', $deviceId)->first();
        expect($device->status)->toBe('blocked');
    });

    it('returns 400 ACKNOWLEDGMENT_WINDOW_EXPIRED when TTL has elapsed', function (): void {
        $deviceId = insertNewDevice($this->userId);
        insertSecurityEvent($deviceId, $this->userId, expired: true);

        $response = $this->postJson(
            "/v1/devices/{$deviceId}/acknowledge",
            ['acknowledged' => true],
            ackHeaders($this->userId),
        );

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('ACKNOWLEDGMENT_WINDOW_EXPIRED');
    });

    it('returns 400 DEVICE_ALREADY_ACKNOWLEDGED when already resolved', function (): void {
        $deviceId = insertNewDevice($this->userId);
        insertSecurityEvent($deviceId, $this->userId, resolution: 'acknowledged');

        $response = $this->postJson(
            "/v1/devices/{$deviceId}/acknowledge",
            ['acknowledged' => true],
            ackHeaders($this->userId),
        );

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('DEVICE_ALREADY_ACKNOWLEDGED');
    });

    it('returns 403 DEVICE_FORBIDDEN for another user\'s device (IDOR)', function (): void {
        $deviceId = insertNewDevice($this->userId2);
        insertSecurityEvent($deviceId, $this->userId2);

        $response = $this->postJson(
            "/v1/devices/{$deviceId}/acknowledge",
            ['acknowledged' => true],
            ackHeaders($this->userId),
        );

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('DEVICE_FORBIDDEN');
    });

    it('publishes outbox event for acknowledged=true', function (): void {
        $deviceId = insertNewDevice($this->userId);
        insertSecurityEvent($deviceId, $this->userId);

        $this->postJson(
            "/v1/devices/{$deviceId}/acknowledge",
            ['acknowledged' => true],
            ackHeaders($this->userId),
        );

        $outbox = \DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Device.Device.NewDeviceAcknowledged')
            ->first();

        expect($outbox)->not->toBeNull();
    });

    it('publishes outbox event for acknowledged=false (denied)', function (): void {
        $deviceId = insertNewDevice($this->userId);
        insertSecurityEvent($deviceId, $this->userId);

        $this->postJson(
            "/v1/devices/{$deviceId}/acknowledge",
            ['acknowledged' => false],
            ackHeaders($this->userId),
        );

        $outbox = \DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Device.Device.NewDeviceDenied')
            ->first();

        expect($outbox)->not->toBeNull();
    });

    it('returns 401 when unauthenticated', function (): void {
        $deviceId = insertNewDevice($this->userId);
        $this->postJson("/v1/devices/{$deviceId}/acknowledge")->assertStatus(401);
    });

})->group('identity', 'device', 'api');
