<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Handlers;

use App\Modules\Identity\Application\Commands\AcknowledgeNewDeviceCommand;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Events\DeviceNewDeviceAcknowledgedEvent;
use App\Modules\Identity\Domain\Events\DeviceNewDeviceDeniedEvent;
use App\Modules\Identity\Domain\Services\DeviceSecurityService;
use App\Modules\Identity\Domain\Services\IdentityMetrics;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;

final class AcknowledgeNewDeviceHandler
{
    public function __construct(
        private readonly DeviceRepositoryInterface  $devices,
        private readonly DeviceSecurityService     $deviceSecurityService,
        private readonly EventPublisherInterface   $events,
        private readonly AuditServiceInterface     $audit,
        private readonly IdempotencyServiceInterface $idempotency,
        private readonly IdentityMetrics           $metrics,
    ) {}

    public function handle(AcknowledgeNewDeviceCommand $command): array
    {
        // (1) Idempotency check
        $cached = $this->idempotency->findCompletedResponse($command->idempotencyKey, $command->userId);
        if ($cached !== null) {
            return $cached['body'];
        }

        // (2) Load device, IDOR check
        $device = $this->devices->findById($command->deviceId);

        if ($device === null || (string) $device->user_id !== $command->userId) {
            throw new \RuntimeException('DEVICE_FORBIDDEN');
        }

        $lock = $this->idempotency->acquireLock(
            $command->idempotencyKey,
            $command->userId,
            hash('sha256', json_encode($command) ?: ''),
            'POST',
            '/v1/devices/' . $command->deviceId . '/acknowledge',
            $command->correlationId,
        );

        // (3) Process acknowledgment via DeviceSecurityService (handles window check + already-ack check)
        $sessionsRevoked = $this->deviceSecurityService->processAcknowledgment(
            device:        $device,
            acknowledged:  $command->acknowledged,
            userId:        $command->userId,
            actorType:     $command->actorType,
            correlationId: $command->correlationId,
            ipHash:        $command->ipHash,
        );

        // Publish outbox events — must be inside a transaction (FR-050).
        // processAcknowledgment() manages its own internal transactions; we open a
        // separate one here solely for the outbox insert + audit record.
        DB::connection('platform')->transaction(function () use ($command, $sessionsRevoked): void {
            if ($command->acknowledged) {
                $this->events->publish(new DeviceNewDeviceAcknowledgedEvent(
                    deviceId:      $command->deviceId,
                    actorId:       $command->userId,
                    correlationId: $command->correlationId,
                    userId:        $command->userId,
                ));
            } else {
                $this->events->publish(new DeviceNewDeviceDeniedEvent(
                    deviceId:        $command->deviceId,
                    actorId:         $command->userId,
                    correlationId:   $command->correlationId,
                    userId:          $command->userId,
                    sessionsRevoked: $sessionsRevoked,
                ));
            }
        });

        if ($command->acknowledged) {
            $this->audit->record(new AuditEventData(
                action:        'identity.device.new-device-acknowledged',
                actorType:     $command->actorType,
                resourceType:  'identity.device',
                correlationId: $command->correlationId,
                outcome:       'success',
                actorId:       $command->userId,
                resourceId:    $command->deviceId,
                afterState:    ['acknowledged' => true],
                ipAddress:     $command->ipHash,
            ));
        } else {
            $this->audit->record(new AuditEventData(
                action:        'identity.device.new-device-denied',
                actorType:     $command->actorType,
                resourceType:  'identity.device',
                correlationId: $command->correlationId,
                outcome:       'warn',
                actorId:       $command->userId,
                resourceId:    $command->deviceId,
                afterState:    ['sessions_revoked' => $sessionsRevoked],
                ipAddress:     $command->ipHash,
            ));
        }

        $result = [
            'acknowledged'    => $command->acknowledged,
            'sessions_revoked' => $sessionsRevoked,
        ];

        // (6) Store idempotency response
        $this->idempotency->recordResponse($lock, 200, $result);

        // (7) Increment metric
        $this->metrics->incrementNewDeviceNotificationAcknowledged($command->acknowledged);

        return $result;
    }
}
