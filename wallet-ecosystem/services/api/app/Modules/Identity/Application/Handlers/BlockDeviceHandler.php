<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Handlers;

use App\Modules\Identity\Application\Commands\BlockDeviceCommand;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Exceptions\DeviceAlreadyBlockedException;
use App\Modules\Identity\Domain\Services\DeviceTrustService;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;

final class BlockDeviceHandler
{
    public function __construct(
        private readonly DeviceRepositoryInterface  $devices,
        private readonly DeviceTrustService        $trustService,
        private readonly IdempotencyServiceInterface $idempotency,
    ) {}

    public function handle(BlockDeviceCommand $command): array
    {
        // (1) Idempotency check
        $cached = $this->idempotency->findCompletedResponse($command->idempotencyKey, $command->userId);
        if ($cached !== null) {
            return $cached['body'];
        }

        // (2) Load device
        $device = $this->devices->findById($command->deviceId);

        if ($device === null) {
            throw new \RuntimeException('DEVICE_NOT_FOUND');
        }

        // (3) IDOR assert for user path
        if (! $command->isAdminAction && (string) $device->user_id !== $command->userId) {
            throw new \RuntimeException('DEVICE_FORBIDDEN');
        }

        // (4) Already blocked check — use different idempotency key path
        if ($device->status === 'blocked') {
            throw DeviceAlreadyBlockedException::make();
        }

        $lock = $this->idempotency->acquireLock(
            $command->idempotencyKey,
            $command->userId,
            hash('sha256', json_encode($command) ?: ''),
            'POST',
            '/v1/devices/' . $command->deviceId . '/block',
            $command->correlationId,
        );

        // (5) Delegate to DeviceTrustService (handles DB transaction + lockForUpdate T134)
        $sessionsRevoked = $this->trustService->blockDevice(
            deviceId:     $command->deviceId,
            userId:       (string) $device->user_id,
            reason:       $command->reason,
            isAdmin:      $command->isAdminAction,
            adminActorId: $command->adminActorId,
            actorType:    $command->actorType,
            correlationId: $command->correlationId,
            ipHash:       $command->ipHash,
        );

        $result = [
            'device_id'        => $command->deviceId,
            'status'           => 'blocked',
            'sessions_revoked' => $sessionsRevoked,
        ];

        // (7) Store idempotency response
        $this->idempotency->recordResponse($lock, 200, $result);

        return $result;
    }
}
