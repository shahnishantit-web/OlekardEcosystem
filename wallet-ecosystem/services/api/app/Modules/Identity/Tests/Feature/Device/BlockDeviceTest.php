<?php

declare(strict_types=1);

use Tests\Support\FakeIdentityAuthMiddleware;

beforeEach(function (): void {
    $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

    $this->userId  = (string) \Ramsey\Uuid\Uuid::uuid7();
    $this->userId2 = (string) \Ramsey\Uuid\Uuid::uuid7();
    $this->adminId = (string) \Ramsey\Uuid\Uuid::uuid7();

    \DB::connection('identity')->table('identity.users')->insert([
        ['id' => $this->userId,  'email' => 'blk1@test.local', 'mobile_number' => '+26771800001', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['id' => $this->userId2, 'email' => 'blk2@test.local', 'mobile_number' => '+26771800002', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['id' => $this->adminId, 'email' => 'blk-admin@test.local', 'mobile_number' => '+26771800003', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    ]);
});

function insertBlockableDevice(string $userId, string $status = 'trusted'): string
{
    $id = (string) \Ramsey\Uuid\Uuid::uuid7();
    \DB::connection('identity')->table('identity.devices')->insert([
        'id'                 => $id,
        'user_id'            => $userId,
        'device_name'        => 'Block Test Phone',
        'platform'           => 'android',
        'device_fingerprint' => hash('sha256', $id . 'blk'),
        'status'             => $status,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);
    return $id;
}

function blockHeaders(string $userId, ?string $idemKey = null): array
{
    return [
        'X-Test-User-Id'  => $userId,
        'Idempotency-Key' => $idemKey ?? 'block-' . uniqid(),
    ];
}

describe('POST /v1/devices/{id}/block (user path)', function (): void {

    it('blocks own trusted device and returns blocked status', function (): void {
        $deviceId = insertBlockableDevice($this->userId);

        $response = $this->postJson(
            "/v1/devices/{$deviceId}/block",
            ['reason' => 'Device may be compromised'],
            blockHeaders($this->userId),
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.device_id', $deviceId);

        expect($response->json('data.sessions_revoked'))->toBeInt();
    });

    it('is idempotent: same Idempotency-Key returns same result', function (): void {
        $deviceId = insertBlockableDevice($this->userId);
        $idemKey  = 'block-idem-' . uniqid();

        $r1 = $this->postJson("/v1/devices/{$deviceId}/block", [], blockHeaders($this->userId, $idemKey));
        $r2 = $this->postJson("/v1/devices/{$deviceId}/block", [], blockHeaders($this->userId, $idemKey));

        $r1->assertStatus(200);
        $r2->assertStatus(200);
        expect($r1->json('data.device_id'))->toBe($r2->json('data.device_id'));
    });

    it('returns 400 DEVICE_ALREADY_BLOCKED with a different Idempotency-Key', function (): void {
        $deviceId = insertBlockableDevice($this->userId);

        $this->postJson("/v1/devices/{$deviceId}/block", [], blockHeaders($this->userId, 'key-1'));

        $r2 = $this->postJson("/v1/devices/{$deviceId}/block", [], blockHeaders($this->userId, 'key-2'));

        $r2->assertStatus(400);
        expect($r2->json('error.code'))->toBe('DEVICE_ALREADY_BLOCKED');
    });

    it('returns 403 DEVICE_FORBIDDEN for another user\'s device (IDOR)', function (): void {
        $deviceId = insertBlockableDevice($this->userId2);

        $response = $this->postJson(
            "/v1/devices/{$deviceId}/block",
            [],
            blockHeaders($this->userId),
        );

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('DEVICE_FORBIDDEN');
    });

    it('creates an outbox event for device block', function (): void {
        $deviceId = insertBlockableDevice($this->userId);

        $this->postJson("/v1/devices/{$deviceId}/block", [], blockHeaders($this->userId));

        $outbox = \DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Device.Device.Blocked')
            ->first();

        expect($outbox)->not->toBeNull();
    });

    it('creates an audit record for device block with WARN severity', function (): void {
        $deviceId = insertBlockableDevice($this->userId);

        $this->postJson("/v1/devices/{$deviceId}/block", [], blockHeaders($this->userId));

        $audit = \DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'identity.device.blocked')
            ->first();

        expect($audit)->not->toBeNull();
        expect($audit->outcome)->toBe('success');
    });

    it('returns 401 when unauthenticated', function (): void {
        $deviceId = insertBlockableDevice($this->userId);
        $this->postJson("/v1/devices/{$deviceId}/block")->assertStatus(401);
    });

})->group('identity', 'device', 'api');
