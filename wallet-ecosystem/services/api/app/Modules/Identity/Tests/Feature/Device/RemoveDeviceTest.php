<?php

declare(strict_types=1);

use Tests\Support\FakeIdentityAuthMiddleware;

beforeEach(function (): void {
    $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

    $this->userId  = (string) \Ramsey\Uuid\Uuid::uuid7();
    $this->userId2 = (string) \Ramsey\Uuid\Uuid::uuid7();

    \DB::connection('identity')->table('identity.users')->insert([
        ['id' => $this->userId,  'email' => 'rem1@test.local', 'mobile_number' => '+26771700001', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['id' => $this->userId2, 'email' => 'rem2@test.local', 'mobile_number' => '+26771700002', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    ]);
});

function insertRemovableDevice(string $userId, string $status = 'trusted'): string
{
    $id = (string) \Ramsey\Uuid\Uuid::uuid7();
    \DB::connection('identity')->table('identity.devices')->insert([
        'id'                 => $id,
        'user_id'            => $userId,
        'device_name'        => 'Remove Test Phone',
        'platform'           => 'android',
        'device_fingerprint' => hash('sha256', $id . 'rem'),
        'status'             => $status,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);
    return $id;
}

function removeHeaders(string $userId, ?string $idemKey = null): array
{
    return [
        'X-Test-User-Id'  => $userId,
        'Idempotency-Key' => $idemKey ?? 'remove-' . uniqid(),
    ];
}

describe('DELETE /v1/devices/{id}', function (): void {

    it('removes a trusted device and returns sessions_revoked count', function (): void {
        $deviceId = insertRemovableDevice($this->userId);

        $response = $this->deleteJson("/v1/devices/{$deviceId}", [], removeHeaders($this->userId));

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['device_id', 'sessions_revoked']]);

        expect($response->json('data.device_id'))->toBe($deviceId);
        expect($response->json('data.sessions_revoked'))->toBe(0);
    });

    it('returns zero sessions_revoked when device had no active sessions', function (): void {
        $deviceId = insertRemovableDevice($this->userId);

        $response = $this->deleteJson("/v1/devices/{$deviceId}", [], removeHeaders($this->userId));

        $response->assertStatus(200);
        expect($response->json('data.sessions_revoked'))->toBe(0);
    });

    it('blocked device can also be removed', function (): void {
        $deviceId = insertRemovableDevice($this->userId, 'blocked');

        $response = $this->deleteJson("/v1/devices/{$deviceId}", [], removeHeaders($this->userId));

        $response->assertStatus(200);
    });

    it('returns 400 INVALID_STATUS_TRANSITION for already-revoked device', function (): void {
        $deviceId = insertRemovableDevice($this->userId, 'revoked');

        $response = $this->deleteJson("/v1/devices/{$deviceId}", [], removeHeaders($this->userId));

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('INVALID_STATUS_TRANSITION');
    });

    it('returns 403 DEVICE_FORBIDDEN for another user\'s device (IDOR)', function (): void {
        $deviceId = insertRemovableDevice($this->userId2);

        $response = $this->deleteJson("/v1/devices/{$deviceId}", [], removeHeaders($this->userId));

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('DEVICE_FORBIDDEN');
    });

    it('is idempotent: same Idempotency-Key returns same result', function (): void {
        $deviceId = insertRemovableDevice($this->userId);
        $idemKey  = 'remove-idem-' . uniqid();

        $r1 = $this->deleteJson("/v1/devices/{$deviceId}", [], removeHeaders($this->userId, $idemKey));
        $r2 = $this->deleteJson("/v1/devices/{$deviceId}", [], removeHeaders($this->userId, $idemKey));

        $r1->assertStatus(200);
        $r2->assertStatus(200);
        expect($r1->json('data.device_id'))->toBe($r2->json('data.device_id'));
    });

    it('creates an outbox event on removal', function (): void {
        $deviceId = insertRemovableDevice($this->userId);

        $this->deleteJson("/v1/devices/{$deviceId}", [], removeHeaders($this->userId));

        $outbox = \DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Device.Device.Removed')
            ->first();

        expect($outbox)->not->toBeNull();
    });

    it('creates an audit record on removal', function (): void {
        $deviceId = insertRemovableDevice($this->userId);

        $this->deleteJson("/v1/devices/{$deviceId}", [], removeHeaders($this->userId));

        $audit = \DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'identity.device.removed')
            ->first();

        expect($audit)->not->toBeNull();
    });

    it('returns 401 when unauthenticated', function (): void {
        $deviceId = insertRemovableDevice($this->userId);
        $this->deleteJson("/v1/devices/{$deviceId}")->assertStatus(401);
    });

})->group('identity', 'device', 'api');
