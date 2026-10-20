<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Handlers;

use App\Modules\Identity\Application\Commands\RemoveDeviceCommand;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Exceptions\InvalidStatusTransitionException;
use App\Modules\Identity\Domain\Services\DeviceTrustService;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;

final class RemoveDeviceHandler
{
    public function __construct(
        private readonly DeviceRepositoryInterface  $devices,
        private readonly DeviceTrustService        $trustService,
        private readonly IdempotencyServiceInterface $idempotency,
    ) {}

    public function handle(RemoveDeviceCommand $command): array
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

        // (3) IDOR check — skip for admin
        if (! $command->isAdminAction && (string) $device->user_id !== $command->userId) {
            throw new \RuntimeException('DEVICE_FORBIDDEN');
        }

        // Guard: revoked devices cannot be removed again
        if ($device->status === 'revoked') {
            throw InvalidStatusTransitionException::make('revoked', 'revoked');
        }

        $lock = $this->idempotency->acquireLock(
            $command->idempotencyKey,
            $command->userId,
            hash('sha256', json_encode($command) ?: ''),
            'DELETE',
            '/v1/devices/' . $command->deviceId,
            $command->correlationId,
        );

        // (4) Delegate to DeviceTrustService (handles DB transaction, T134 lockForUpdate inside)
        $sessionsRevoked = $this->trustService->removeDevice(
            deviceId:     $command->deviceId,
            userId:       (string) $device->user_id,
            isAdmin:      $command->isAdminAction,
            adminActorId: $command->adminActorId,
            actorType:    $command->actorType,
            correlationId: $command->correlationId,
            ipHash:       $command->ipHash,
        );

        $result = [
            'device_id'        => $command->deviceId,
            'status'           => 'revoked',
            'sessions_revoked' => $sessionsRevoked,
        ];

        // (6) Store idempotency response
        $this->idempotency->recordResponse($lock, 200, $result);

        return $result;
    }
}
