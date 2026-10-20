<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Handlers;

use App\Modules\Identity\Application\Commands\UnblockDeviceCommand;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Exceptions\InvalidStatusTransitionException;
use App\Modules\Identity\Domain\Services\DeviceTrustService;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;

final class UnblockDeviceHandler
{
    public function __construct(
        private readonly DeviceRepositoryInterface  $devices,
        private readonly DeviceTrustService        $trustService,
        private readonly IdempotencyServiceInterface $idempotency,
    ) {}

    public function handle(UnblockDeviceCommand $command): array
    {
        // (1) Idempotency check
        $cached = $this->idempotency->findCompletedResponse($command->idempotencyKey, $command->adminActorId);
        if ($cached !== null) {
            return $cached['body'];
        }

        // (2) Load device
        $device = $this->devices->findById($command->deviceId);

        if ($device === null) {
            throw new \RuntimeException('DEVICE_NOT_FOUND');
        }

        // (3) Status guard: can only unblock a blocked device
        if ($device->status !== 'blocked') {
            throw InvalidStatusTransitionException::make($device->status, 'trusted');
        }

        $lock = $this->idempotency->acquireLock(
            $command->idempotencyKey,
            $command->adminActorId,
            hash('sha256', json_encode($command) ?: ''),
            'POST',
            '/v1/admin/devices/' . $command->deviceId . '/unblock',
            $command->correlationId,
        );

        // (4) Delegate to DeviceTrustService
        $this->trustService->unblockDevice(
            deviceId:     $command->deviceId,
            adminActorId: $command->adminActorId,
            reason:       $command->reason,
            correlationId: $command->correlationId,
            ipHash:       $command->ipHash,
        );

        $result = [
            'device_id' => $command->deviceId,
            'status'    => 'trusted',
        ];

        // (5) Store idempotency response
        $this->idempotency->recordResponse($lock, 200, $result);

        return $result;
    }
}
