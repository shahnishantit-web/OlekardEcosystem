<?php

declare(strict_types=1);

use Tests\Support\FakeIdentityAuthMiddleware;

beforeEach(function (): void {
    $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

    $this->userId  = (string) \Ramsey\Uuid\Uuid::uuid7();
    $this->userId2 = (string) \Ramsey\Uuid\Uuid::uuid7();

    \DB::connection('identity')->table('identity.users')->insert([
        ['id' => $this->userId,  'email' => 'list1@test.local', 'mobile_number' => '+26771500001', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['id' => $this->userId2, 'email' => 'list2@test.local', 'mobile_number' => '+26771500002', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    ]);
});

function insertDevice(string $userId, string $id, string $status = 'trusted', string $platform = 'android'): void
{
    \DB::connection('identity')->table('identity.devices')->insert([
        'id'                 => $id,
        'user_id'            => $userId,
        'device_name'        => 'Device ' . substr($id, 0, 8),
        'platform'           => $platform,
        'device_fingerprint' => hash('sha256', $id),
        'status'             => $status,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);
}

describe('GET /v1/devices', function (): void {

    it('returns 200 with paginated device list', function (): void {
        $id1 = (string) \Ramsey\Uuid\Uuid::uuid7();
        $id2 = (string) \Ramsey\Uuid\Uuid::uuid7();

        insertDevice($this->userId, $id1);
        insertDevice($this->userId, $id2, 'pending_verification');

        $response = $this->getJson('/v1/devices', ['X-Test-User-Id' => $this->userId]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total', 'last_page']]);

        expect($response->json('meta.total'))->toBe(2);
    });

    it('returns empty data array when user has no devices', function (): void {
        $response = $this->getJson('/v1/devices', ['X-Test-User-Id' => $this->userId]);

        $response->assertStatus(200);
        expect($response->json('data'))->toBe([]);
        expect($response->json('meta.total'))->toBe(0);
    });

    it('does NOT include device_fingerprint in any response item', function (): void {
        insertDevice($this->userId, (string) \Ramsey\Uuid\Uuid::uuid7());

        $response = $this->getJson('/v1/devices', ['X-Test-User-Id' => $this->userId]);

        $response->assertStatus(200);
        $body = $response->getContent();

        expect($body)->not->toContain('device_fingerprint');
        expect($body)->not->toContain('fingerprint');
    });

    it('filters by status query parameter', function (): void {
        insertDevice($this->userId, (string) \Ramsey\Uuid\Uuid::uuid7(), 'trusted');
        insertDevice($this->userId, (string) \Ramsey\Uuid\Uuid::uuid7(), 'pending_verification');

        $response = $this->getJson('/v1/devices?status=trusted', ['X-Test-User-Id' => $this->userId]);

        $response->assertStatus(200);
        expect($response->json('meta.total'))->toBe(1);
        expect($response->json('data.0.status'))->toBe('trusted');
    });

    it('filters by platform query parameter', function (): void {
        insertDevice($this->userId, (string) \Ramsey\Uuid\Uuid::uuid7(), 'trusted', 'android');
        insertDevice($this->userId, (string) \Ramsey\Uuid\Uuid::uuid7(), 'trusted', 'ios');

        $response = $this->getJson('/v1/devices?platform=ios', ['X-Test-User-Id' => $this->userId]);

        $response->assertStatus(200);
        expect($response->json('meta.total'))->toBe(1);
        expect($response->json('data.0.platform'))->toBe('ios');
    });

    it('returns only devices belonging to the authenticated user (IDOR protection)', function (): void {
        insertDevice($this->userId,  (string) \Ramsey\Uuid\Uuid::uuid7(), 'trusted');
        insertDevice($this->userId2, (string) \Ramsey\Uuid\Uuid::uuid7(), 'trusted');

        $response = $this->getJson('/v1/devices', ['X-Test-User-Id' => $this->userId]);

        $response->assertStatus(200);
        expect($response->json('meta.total'))->toBe(1);
    });

    it('returns 401 when unauthenticated', function (): void {
        $this->getJson('/v1/devices')->assertStatus(401);
    });

    it('respects per_page query parameter', function (): void {
        for ($i = 0; $i < 5; $i++) {
            insertDevice($this->userId, (string) \Ramsey\Uuid\Uuid::uuid7());
        }

        $response = $this->getJson('/v1/devices?per_page=2&page=1', ['X-Test-User-Id' => $this->userId]);

        $response->assertStatus(200);
        expect(count($response->json('data')))->toBe(2);
        expect($response->json('meta.per_page'))->toBe(2);
    });

})->group('identity', 'device', 'api');
