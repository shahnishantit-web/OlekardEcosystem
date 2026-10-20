<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Handlers;

use App\Modules\Identity\Application\Commands\TrustDeviceCommand;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceTrustHistoryRepositoryInterface;
use App\Modules\Identity\Domain\Events\DeviceTrustPromotedEvent;
use App\Modules\Identity\Domain\Exceptions\DeviceBlockedException;
use App\Modules\Identity\Domain\Exceptions\InvalidStatusTransitionException;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class TrustDeviceHandler
{
    public function __construct(
        private readonly DeviceRepositoryInterface           $devices,
        private readonly DeviceTrustHistoryRepositoryInterface $trustHistory,
        private readonly EventPublisherInterface             $events,
        private readonly AuditServiceInterface               $audit,
        private readonly IdempotencyServiceInterface         $idempotency,
    ) {}

    public function handle(TrustDeviceCommand $command): array
    {
        // (1) Idempotency check
        $cached = $this->idempotency->findCompletedResponse($command->idempotencyKey, $command->userId);
        if ($cached !== null) {
            return $cached['body'];
        }

        // (2) Load device, IDOR assert
        $device = $this->devices->findById($command->deviceId);

        if ($device === null || (string) $device->user_id !== $command->userId) {
            throw new \RuntimeException('DEVICE_FORBIDDEN');
        }

        // (3) Status guard checks
        if ($device->status === 'blocked') {
            throw DeviceBlockedException::make();
        }

        if ($device->status === 'revoked') {
            throw InvalidStatusTransitionException::make('revoked', 'trusted');
        }

        // (4) If already trusted: idempotent return
        if ($device->status === 'trusted') {
            $result = ['device_id' => $device->id, 'status' => 'trusted'];

            $lock = $this->idempotency->acquireLock(
                $command->idempotencyKey,
                $command->userId,
                hash('sha256', json_encode($command) ?: ''),
                'POST',
                '/v1/devices/' . $command->deviceId . '/trust',
                $command->correlationId,
            );
            $this->idempotency->recordResponse($lock, 200, $result);

            return $result;
        }

        $lock = $this->idempotency->acquireLock(
            $command->idempotencyKey,
            $command->userId,
            hash('sha256', json_encode($command) ?: ''),
            'POST',
            '/v1/devices/' . $command->deviceId . '/trust',
            $command->correlationId,
        );

        $fromStatus = $device->status;

        // (5) DB transaction
        DB::connection('platform')->transaction(function () use ($command, $fromStatus): void {
            // T134: lockForUpdate to prevent concurrent status transitions
            \App\Modules\Identity\Infrastructure\Models\Device::where('id', $command->deviceId)->lockForUpdate()->firstOrFail();

            $this->devices->updateStatus($command->deviceId, 'trusted', ['trusted_at' => now()]);

            $this->trustHistory->append([
                'id'             => (string) Uuid::uuid7(),
                'device_id'      => $command->deviceId,
                'user_id'        => $command->userId,
                'from_status'    => $fromStatus,
                'to_status'      => 'trusted',
                'actor_id'       => $command->userId,
                'actor_type'     => $command->actorType,
                'reason'         => $command->reason,
                'correlation_id' => $command->correlationId,
                'created_at'     => now(),
            ]);

            $this->events->publish(new DeviceTrustPromotedEvent(
                deviceId:      $command->deviceId,
                actorId:       $command->userId,
                correlationId: $command->correlationId,
                userId:        $command->userId,
                fromStatus:    $fromStatus,
            ));

            $this->audit->record(new AuditEventData(
                action:        'identity.device.trusted',
                actorType:     $command->actorType,
                resourceType:  'identity.device',
                correlationId: $command->correlationId,
                outcome:       'success',
                actorId:       $command->userId,
                resourceId:    $command->deviceId,
                beforeState:   ['status' => $fromStatus],
                afterState:    ['status' => 'trusted'],
                ipAddress:     $command->ipHash,
            ));
        });

        $result = ['device_id' => $command->deviceId, 'status' => 'trusted'];

        // (6) Store idempotency response
        $this->idempotency->recordResponse($lock, 200, $result);

        return $result;
    }
}
