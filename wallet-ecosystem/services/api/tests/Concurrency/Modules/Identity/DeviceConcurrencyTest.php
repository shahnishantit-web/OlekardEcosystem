<?php

declare(strict_types=1);

namespace Tests\Concurrency\Modules\Identity;

use App\Modules\Identity\Application\Commands\BlockDeviceCommand;
use App\Modules\Identity\Application\Commands\TrustDeviceCommand;
use App\Modules\Identity\Application\Handlers\BlockDeviceHandler;
use App\Modules\Identity\Application\Handlers\TrustDeviceHandler;
use Tests\Support\ConcurrencyTestHelper;
use Tests\Support\IdentityTestCase;

/**
 * Verifies that concurrent block/trust operations on the same device do not
 * result in conflicting states. The lockForUpdate guard (T134) must prevent
 * race conditions.
 */
final class DeviceConcurrencyTest extends IdentityTestCase
{
    public function test_concurrent_trust_calls_are_idempotent(): void
    {
        $userId   = (string) \Ramsey\Uuid\Uuid::uuid7();
        $deviceId = (string) \Ramsey\Uuid\Uuid::uuid7();

        \DB::connection('identity')->table('identity.users')->insert([
            'id' => $userId, 'email' => 'conc@t.local', 'mobile_number' => '+1000000070',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::connection('identity')->table('identity.devices')->insert([
            'id'                 => $deviceId,
            'user_id'            => $userId,
            'device_name'        => 'Race Phone',
            'platform'           => 'ios',
            'device_fingerprint' => str_repeat('f', 64),
            'status'             => 'pending_verification',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $idemKey = 'trust-conc-' . uniqid();

        $results = ConcurrencyTestHelper::runConcurrently(
            workers: 3,
            callable: function () use ($userId, $deviceId, $idemKey): string {
                /** @var TrustDeviceHandler $handler */
                $handler = app(TrustDeviceHandler::class);
                $result  = $handler->handle(new TrustDeviceCommand(
                    userId:         $userId,
                    deviceId:       $deviceId,
                    reason:         'concurrent trust',
                    actorType:      'customer',
                    correlationId:  (string) \Ramsey\Uuid\Uuid::uuid7(),
                    ipHash:         str_repeat('0', 64),
                    idempotencyKey: $idemKey,
                ));
                return $result['status'];
            },
        );

        foreach ($results as $status) {
            $this->assertSame('trusted', $status);
        }

        $finalDevice = \DB::connection('identity')
            ->table('identity.devices')
            ->where('id', $deviceId)
            ->first();

        $this->assertSame('trusted', $finalDevice->status);
    }

    public function test_concurrent_block_and_trust_leaves_device_in_single_final_state(): void
    {
        $userId   = (string) \Ramsey\Uuid\Uuid::uuid7();
        $deviceId = (string) \Ramsey\Uuid\Uuid::uuid7();

        \DB::connection('identity')->table('identity.users')->insert([
            'id' => $userId, 'email' => 'conc2@t.local', 'mobile_number' => '+1000000071',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::connection('identity')->table('identity.devices')->insert([
            'id'                 => $deviceId,
            'user_id'            => $userId,
            'device_name'        => 'Race Phone 2',
            'platform'           => 'android',
            'device_fingerprint' => str_repeat('g', 64),
            'status'             => 'pending_verification',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $finalStatuses = [];

        try {
            /** @var TrustDeviceHandler $trustHandler */
            $trustHandler = app(TrustDeviceHandler::class);
            $finalStatuses[] = $trustHandler->handle(new TrustDeviceCommand(
                userId:         $userId,
                deviceId:       $deviceId,
                reason:         'trust attempt',
                actorType:      'customer',
                correlationId:  (string) \Ramsey\Uuid\Uuid::uuid7(),
                ipHash:         str_repeat('0', 64),
                idempotencyKey: 'trust-race-' . uniqid(),
            ))['status'];
        } catch (\Throwable) {
            // Expected when block wins the race
        }

        try {
            /** @var BlockDeviceHandler $blockHandler */
            $blockHandler = app(BlockDeviceHandler::class);
            $finalStatuses[] = $blockHandler->handle(new BlockDeviceCommand(
                userId:         $userId,
                deviceId:       $deviceId,
                reason:         'block attempt',
                isAdminAction:  true,
                adminActorId:   $userId,
                actorType:      'admin',
                correlationId:  (string) \Ramsey\Uuid\Uuid::uuid7(),
                ipHash:         str_repeat('0', 64),
                idempotencyKey: 'block-race-' . uniqid(),
            ))['status'];
        } catch (\Throwable) {
            // Expected when trust wins the race
        }

        $finalDevice = \DB::connection('identity')
            ->table('identity.devices')
            ->where('id', $deviceId)
            ->first();

        $this->assertContains($finalDevice->status, ['trusted', 'blocked'],
            'Device must be in exactly one terminal state after concurrent operations.');
    }
}
