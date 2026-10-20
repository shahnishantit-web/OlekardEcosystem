<?php

declare(strict_types=1);

use Tests\Support\FakeIdentityAuthMiddleware;

beforeEach(function (): void {
    $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

    $this->userId  = (string) \Ramsey\Uuid\Uuid::uuid7();
    $this->userId2 = (string) \Ramsey\Uuid\Uuid::uuid7();

    \DB::connection('identity')->table('identity.users')->insert([
        ['id' => $this->userId,  'email' => 'trust1@test.local', 'mobile_number' => '+26771600001', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['id' => $this->userId2, 'email' => 'trust2@test.local', 'mobile_number' => '+26771600002', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    ]);
});

function insertPendingDevice(string $userId, string $status = 'pending_verification'): string
{
    $id = (string) \Ramsey\Uuid\Uuid::uuid7();
    \DB::connection('identity')->table('identity.devices')->insert([
        'id'                 => $id,
        'user_id'            => $userId,
        'device_name'        => 'Test Phone',
        'platform'           => 'android',
        'device_fingerprint' => hash('sha256', $id),
        'status'             => $status,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);
    return $id;
}

function trustHeaders(string $userId, ?string $idemKey = null): array
{
    return [
        'X-Test-User-Id'  => $userId,
        'Idempotency-Key' => $idemKey ?? 'trust-' . uniqid(),
    ];
}

describe('POST /v1/devices/{id}/trust', function (): void {

    it('promotes pending_verification device to trusted', function (): void {
        $deviceId = insertPendingDevice($this->userId, 'pending_verification');

        $response = $this->postJson(
            "/v1/devices/{$deviceId}/trust",
            ['reason' => 'Owner confirmed'],
            trustHeaders($this->userId),
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'trusted')
            ->assertJsonPath('data.device_id', $deviceId);
    });

    it('is idempotent: same Idempotency-Key returns same result', function (): void {
        $deviceId = insertPendingDevice($this->userId);
        $idemKey  = 'trust-idem-' . uniqid();

        $r1 = $this->postJson("/v1/devices/{$deviceId}/trust", ['reason' => 'First'], trustHeaders($this->userId, $idemKey));
        $r2 = $this->postJson("/v1/devices/{$deviceId}/trust", ['reason' => 'Second'], trustHeaders($this->userId, $idemKey));

        $r1->assertStatus(200);
        $r2->assertStatus(200);
        expect($r1->json('data.status'))->toBe($r2->json('data.status'));
    });

    it('returns 403 DEVICE_BLOCKED when device is blocked', function (): void {
        $deviceId = insertPendingDevice($this->userId, 'blocked');

        $response = $this->postJson(
            "/v1/devices/{$deviceId}/trust",
            [],
            trustHeaders($this->userId),
        );

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('DEVICE_BLOCKED');
    });

    it('returns 400 INVALID_STATUS_TRANSITION when device is revoked', function (): void {
        $deviceId = insertPendingDevice($this->userId, 'revoked');

        $response = $this->postJson(
            "/v1/devices/{$deviceId}/trust",
            [],
            trustHeaders($this->userId),
        );

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('INVALID_STATUS_TRANSITION');
    });

    it('returns 403 DEVICE_FORBIDDEN for another user\'s device (IDOR)', function (): void {
        $deviceId = insertPendingDevice($this->userId2, 'pending_verification');

        $response = $this->postJson(
            "/v1/devices/{$deviceId}/trust",
            [],
            trustHeaders($this->userId),
        );

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('DEVICE_FORBIDDEN');
    });

    it('creates a trust history record on success', function (): void {
        $deviceId = insertPendingDevice($this->userId);

        $this->postJson("/v1/devices/{$deviceId}/trust", ['reason' => 'OK'], trustHeaders($this->userId));

        $history = \DB::connection('identity')
            ->table('identity.device_trust_history')
            ->where('device_id', $deviceId)
            ->where('to_status', 'trusted')
            ->first();

        expect($history)->not->toBeNull();
    });

    it('creates an outbox event for trust promotion', function (): void {
        $deviceId = insertPendingDevice($this->userId);

        $this->postJson("/v1/devices/{$deviceId}/trust", [], trustHeaders($this->userId));

        $outbox = \DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Device.Trust.Promoted')
            ->first();

        expect($outbox)->not->toBeNull();
    });

    it('creates an audit record for trust promotion', function (): void {
        $deviceId = insertPendingDevice($this->userId);

        $this->postJson("/v1/devices/{$deviceId}/trust", [], trustHeaders($this->userId));

        $audit = \DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'identity.device.trusted')
            ->first();

        expect($audit)->not->toBeNull();
        expect($audit->outcome)->toBe('success');
    });

    it('returns 401 when unauthenticated', function (): void {
        $deviceId = insertPendingDevice($this->userId);
        $this->postJson("/v1/devices/{$deviceId}/trust")->assertStatus(401);
    });

})->group('identity', 'device', 'api');
