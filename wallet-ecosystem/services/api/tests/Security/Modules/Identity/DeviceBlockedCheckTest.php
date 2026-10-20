<?php

declare(strict_types=1);

namespace Tests\Security\Modules\Identity;

use App\Modules\Identity\Domain\Contracts\DeviceBlockCheckServiceInterface;
use App\Modules\Identity\Domain\Services\DeviceBlockCheckService;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Infrastructure\Models\Device;
use App\Modules\Identity\Infrastructure\Repositories\EloquentDeviceRepository;
use Tests\Support\IdentityTestCase;

/**
 * FR-E03-045: Requests from blocked devices must be denied with HTTP 403.
 */
final class DeviceBlockedCheckTest extends IdentityTestCase
{
    private DeviceBlockCheckServiceInterface $blockCheckService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blockCheckService = $this->app->make(DeviceBlockCheckServiceInterface::class);
    }

    public function test_is_blocked_returns_true_for_blocked_device(): void
    {
        $id = (string) \Ramsey\Uuid\Uuid::uuid7();
        $userId = (string) \Ramsey\Uuid\Uuid::uuid7();

        \DB::connection('identity')->table('identity.users')->insert([
            'id' => $userId, 'email' => 'b@t.local', 'mobile_number' => '+1000000099',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::connection('identity')->table('identity.devices')->insert([
            'id'               => $id,
            'user_id'          => $userId,
            'device_name'      => 'Blocked Phone',
            'platform'         => 'android',
            'device_fingerprint' => str_repeat('b', 64),
            'status'           => 'blocked',
            'blocked_at'       => now(),
            'blocked_reason'   => 'Test block',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->assertTrue($this->blockCheckService->isBlocked($id));
    }

    public function test_is_blocked_returns_false_for_trusted_device(): void
    {
        $id = (string) \Ramsey\Uuid\Uuid::uuid7();
        $userId = (string) \Ramsey\Uuid\Uuid::uuid7();

        \DB::connection('identity')->table('identity.users')->insert([
            'id' => $userId, 'email' => 'c@t.local', 'mobile_number' => '+1000000098',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::connection('identity')->table('identity.devices')->insert([
            'id'               => $id,
            'user_id'          => $userId,
            'device_name'      => 'Trusted Phone',
            'platform'         => 'ios',
            'device_fingerprint' => str_repeat('c', 64),
            'status'           => 'trusted',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->assertFalse($this->blockCheckService->isBlocked($id));
    }

    public function test_is_blocked_returns_false_for_empty_device_id(): void
    {
        $this->assertFalse($this->blockCheckService->isBlocked(''));
    }

    public function test_is_blocked_returns_false_for_nonexistent_device(): void
    {
        $this->assertFalse($this->blockCheckService->isBlocked((string) \Ramsey\Uuid\Uuid::uuid7()));
    }
}
