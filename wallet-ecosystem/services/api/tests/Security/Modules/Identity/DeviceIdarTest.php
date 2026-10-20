<?php

declare(strict_types=1);

namespace Tests\Security\Modules\Identity;

use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\IdentityTestCase;

/**
 * BOLA/IDOR prevention: user A must never read or mutate user B's devices.
 */
final class DeviceIdarTest extends IdentityTestCase
{
    private string $userAId;
    private string $userBId;
    private string $deviceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

        $this->userAId = (string) \Ramsey\Uuid\Uuid::uuid7();
        $this->userBId = (string) \Ramsey\Uuid\Uuid::uuid7();

        $this->deviceId = (string) \Ramsey\Uuid\Uuid::uuid7();

        \DB::connection('identity')->table('identity.users')->insert([
            'id'         => $this->userAId,
            'email'      => 'usera@test.local',
            'mobile_number'      => '+1000000001',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::connection('identity')->table('identity.users')->insert([
            'id'         => $this->userBId,
            'email'      => 'userb@test.local',
            'mobile_number'      => '+1000000002',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::connection('identity')->table('identity.devices')->insert([
            'id'                 => $this->deviceId,
            'user_id'            => $this->userBId,
            'device_name'        => "Bob's Phone",
            'platform'           => 'android',
            'device_fingerprint' => str_repeat('a', 64),
            'status'             => 'trusted',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    public function test_user_cannot_read_other_users_device(): void
    {
        $response = $this->getJson('/v1/devices/' . $this->deviceId, [
            'X-Test-User-Id' => $this->userAId,
        ]);

        $response->assertStatus(403);
    }

    public function test_user_cannot_trust_other_users_device(): void
    {
        $response = $this->postJson('/v1/devices/' . $this->deviceId . '/trust', ['reason' => 'test'], [
            'X-Test-User-Id'  => $this->userAId,
            'Idempotency-Key' => 'test-idor-trust-' . uniqid(),
        ]);

        $response->assertStatus(403);
    }

    public function test_user_cannot_remove_other_users_device(): void
    {
        $response = $this->deleteJson('/v1/devices/' . $this->deviceId, [], [
            'X-Test-User-Id'  => $this->userAId,
            'Idempotency-Key' => 'test-idor-remove-' . uniqid(),
        ]);

        $response->assertStatus(403);
    }

    public function test_user_cannot_block_other_users_device(): void
    {
        $response = $this->postJson('/v1/devices/' . $this->deviceId . '/block', ['reason' => 'test'], [
            'X-Test-User-Id'  => $this->userAId,
            'Idempotency-Key' => 'test-idor-block-' . uniqid(),
        ]);

        $response->assertStatus(403);
    }

    public function test_user_can_read_own_device(): void
    {
        $response = $this->getJson('/v1/devices/' . $this->deviceId, [
            'X-Test-User-Id' => $this->userBId,
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.id', $this->deviceId);
    }
}
