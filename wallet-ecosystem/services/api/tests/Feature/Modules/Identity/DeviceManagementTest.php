<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Identity;

use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\IdentityTestCase;

final class DeviceManagementTest extends IdentityTestCase
{
    private string $userId;
    private string $deviceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

        $this->userId   = (string) \Ramsey\Uuid\Uuid::uuid7();
        $this->deviceId = (string) \Ramsey\Uuid\Uuid::uuid7();

        \DB::connection('identity')->table('identity.users')->insert([
            'id'         => $this->userId,
            'email'      => 'dm@test.local',
            'mobile_number'      => '+1000000050',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::connection('identity')->table('identity.devices')->insert([
            'id'                 => $this->deviceId,
            'user_id'            => $this->userId,
            'device_name'        => 'Test Phone',
            'platform'           => 'ios',
            'device_fingerprint' => str_repeat('d', 64),
            'status'             => 'pending_verification',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    public function test_list_devices_returns_paginated_result(): void
    {
        $response = $this->getJson('/v1/devices', [
            'X-Test-User-Id' => $this->userId,
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['success', 'data', 'meta'])
                 ->assertJsonPath('success', true);
    }

    public function test_show_device_returns_device_detail(): void
    {
        $response = $this->getJson('/v1/devices/' . $this->deviceId, [
            'X-Test-User-Id' => $this->userId,
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.id', $this->deviceId)
                 ->assertJsonPath('data.status', 'pending_verification');
    }

    public function test_trust_device_promotes_status(): void
    {
        $response = $this->postJson(
            '/v1/devices/' . $this->deviceId . '/trust',
            ['reason' => 'My personal phone'],
            [
                'X-Test-User-Id'  => $this->userId,
                'Idempotency-Key' => 'trust-test-' . uniqid(),
            ],
        );

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'trusted');
    }

    public function test_trust_is_idempotent(): void
    {
        $idemKey = 'trust-idem-' . uniqid();

        $r1 = $this->postJson(
            '/v1/devices/' . $this->deviceId . '/trust',
            ['reason' => 'First call'],
            ['X-Test-User-Id' => $this->userId, 'Idempotency-Key' => $idemKey],
        );

        $r2 = $this->postJson(
            '/v1/devices/' . $this->deviceId . '/trust',
            ['reason' => 'Second call (duplicate)'],
            ['X-Test-User-Id' => $this->userId, 'Idempotency-Key' => $idemKey],
        );

        $r1->assertStatus(200);
        $r2->assertStatus(200);
        $this->assertSame($r1->json('data.status'), $r2->json('data.status'));
    }

    public function test_remove_device_sets_status_to_revoked(): void
    {
        $response = $this->deleteJson('/v1/devices/' . $this->deviceId, [], [
            'X-Test-User-Id'  => $this->userId,
            'Idempotency-Key' => 'remove-test-' . uniqid(),
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'revoked');
    }

    public function test_list_requires_authentication(): void
    {
        $response = $this->getJson('/v1/devices');
        $response->assertStatus(401);
    }

    public function test_show_returns_404_for_nonexistent_device(): void
    {
        $response = $this->getJson('/v1/devices/' . \Ramsey\Uuid\Uuid::uuid7(), [
            'X-Test-User-Id' => $this->userId,
        ]);

        $response->assertStatus(403); // Forbidden (IDOR protection — no 404 leak)
    }
}
