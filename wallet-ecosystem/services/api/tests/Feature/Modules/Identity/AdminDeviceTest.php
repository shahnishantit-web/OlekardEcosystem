<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Identity;

use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\IdentityTestCase;

final class AdminDeviceTest extends IdentityTestCase
{
    private string $adminId;
    private string $userId;
    private string $deviceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

        $this->adminId  = (string) \Ramsey\Uuid\Uuid::uuid7();
        $this->userId   = (string) \Ramsey\Uuid\Uuid::uuid7();
        $this->deviceId = (string) \Ramsey\Uuid\Uuid::uuid7();

        $adminRole = \DB::connection('identity')->table('identity.roles')->where('slug', 'admin')->first(['id']);

        \DB::connection('identity')->table('identity.users')->insert([
            ['id' => $this->adminId, 'email' => 'admin@t.local', 'mobile_number' => '+1000000060', 'status' => 'active', 'actor_type' => 'ADMIN',    'default_role_id' => $adminRole?->id, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->userId,  'email' => 'user@t.local',  'mobile_number' => '+1000000061', 'status' => 'active', 'actor_type' => 'CUSTOMER',  'default_role_id' => null,            'created_at' => now(), 'updated_at' => now()],
        ]);

        \DB::connection('identity')->table('identity.devices')->insert([
            'id'                 => $this->deviceId,
            'user_id'            => $this->userId,
            'device_name'        => 'User Phone',
            'platform'           => 'android',
            'device_fingerprint' => str_repeat('e', 64),
            'status'             => 'trusted',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    public function test_admin_can_list_user_devices(): void
    {
        $response = $this->getJson('/v1/admin/users/' . $this->userId . '/devices', [
            'X-Test-User-Id'    => $this->adminId,
            'X-Test-Actor-Type' => 'admin',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['success', 'data' => ['data', 'meta']]);
    }

    public function test_admin_can_block_device(): void
    {
        $response = $this->postJson(
            '/v1/admin/devices/' . $this->deviceId . '/block',
            ['reason' => 'Suspicious activity'],
            [
                'X-Test-User-Id'    => $this->adminId,
                'X-Test-Actor-Type' => 'admin',
                'Idempotency-Key'   => 'admin-block-' . uniqid(),
            ],
        );

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'blocked');
    }

    public function test_admin_can_unblock_device(): void
    {
        \DB::connection('identity')->table('identity.devices')
            ->where('id', $this->deviceId)
            ->update(['status' => 'blocked', 'blocked_at' => now(), 'blocked_reason' => 'test']);

        $response = $this->postJson(
            '/v1/admin/devices/' . $this->deviceId . '/unblock',
            ['reason' => 'Confirmed safe'],
            [
                'X-Test-User-Id'    => $this->adminId,
                'X-Test-Actor-Type' => 'admin',
                'Idempotency-Key'   => 'admin-unblock-' . uniqid(),
            ],
        );

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'trusted');
    }

    public function test_admin_can_remove_device(): void
    {
        $response = $this->deleteJson(
            '/v1/admin/devices/' . $this->deviceId,
            [],
            [
                'X-Test-User-Id'    => $this->adminId,
                'X-Test-Actor-Type' => 'admin',
                'Idempotency-Key'   => 'admin-remove-' . uniqid(),
            ],
        );

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'revoked');
    }

    public function test_block_requires_reason(): void
    {
        $response = $this->postJson(
            '/v1/admin/devices/' . $this->deviceId . '/block',
            [],
            [
                'X-Test-User-Id'    => $this->adminId,
                'X-Test-Actor-Type' => 'admin',
                'Idempotency-Key'   => 'admin-block-nreason-' . uniqid(),
            ],
        );

        $response->assertStatus(422);
    }

    public function test_admin_can_list_device_security_events(): void
    {
        $response = $this->getJson('/v1/admin/devices/' . $this->deviceId . '/security-events', [
            'X-Test-User-Id'    => $this->adminId,
            'X-Test-Actor-Type' => 'admin',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['success', 'data' => ['data', 'meta']]);
    }
}
