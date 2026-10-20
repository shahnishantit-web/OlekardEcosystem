<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceTrustHistoryRepositoryInterface;
use App\Modules\Identity\Domain\Events\DeviceBlockedEvent;
use App\Modules\Identity\Domain\Events\DeviceRemovedEvent;
use App\Modules\Identity\Domain\Events\DeviceTrustPromotedEvent;
use App\Modules\Identity\Domain\Events\DeviceUnblockedEvent;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class DeviceTrustService
{
    public function __construct(
        private readonly DeviceRepositoryInterface           $devices,
        private readonly DeviceTrustHistoryRepositoryInterface $trustHistory,
        private readonly SessionService                      $sessions,
        private readonly EventPublisherInterface             $events,
        private readonly AuditServiceInterface               $audit,
        private readonly ConfigurationServiceInterface       $config,
        private readonly IdentityMetrics                     $metrics,
    ) {}

    public function trustDevice(
        string  $deviceId,
        string  $userId,
        ?string $reason,
        string  $actorId,
        string  $actorType,
        string  $correlationId,
        string  $ipHash,
    ): void {
        DB::connection('platform')->transaction(function () use ($deviceId, $userId, $reason, $actorId, $actorType, $correlationId, $ipHash): void {
            $device = $this->devices->findById($deviceId);

            $fromStatus = $device?->status ?? 'unknown';

            $this->devices->updateStatus($deviceId, 'trusted', ['trusted_at' => now()]);

            $this->trustHistory->append([
                'id'             => (string) Uuid::uuid7(),
                'device_id'      => $deviceId,
                'user_id'        => $userId,
                'from_status'    => $fromStatus,
                'to_status'      => 'trusted',
                'actor_id'       => $actorId,
                'actor_type'     => $actorType,
                'reason'         => $reason,
                'correlation_id' => $correlationId,
                'created_at'     => now(),
            ]);

            $this->events->publish(new DeviceTrustPromotedEvent(
                deviceId:      $deviceId,
                actorId:       $actorId,
                correlationId: $correlationId,
                userId:        $userId,
                fromStatus:    $fromStatus,
            ));

            $this->audit->record(new AuditEventData(
                action:        'identity.device.trusted',
                actorType:     $actorType,
                resourceType:  'identity.device',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $actorId,
                resourceId:    $deviceId,
                beforeState:   ['status' => $fromStatus],
                afterState:    ['status' => 'trusted'],
                ipAddress:     $ipHash,
            ));
        });
    }

    /**
     * Remove (revoke) a device. Returns count of sessions revoked.
     */
    public function removeDevice(
        string  $deviceId,
        string  $userId,
        bool    $isAdmin,
        ?string $adminActorId,
        string  $actorType,
        string  $correlationId,
        string  $ipHash,
    ): int {
        $sessionsRevoked = 0;

        DB::connection('platform')->transaction(function () use ($deviceId, $userId, $isAdmin, $adminActorId, $actorType, $correlationId, $ipHash, &$sessionsRevoked): void {
            // T134: lock the device row to prevent concurrent status transitions
            \App\Modules\Identity\Infrastructure\Models\Device::where('id', $deviceId)->lockForUpdate()->firstOrFail();

            $device     = $this->devices->findById($deviceId);
            $fromStatus = $device?->status ?? 'unknown';
            $actorId    = $isAdmin ? ($adminActorId ?? $userId) : $userId;

            $this->devices->updateStatus($deviceId, 'revoked');

            $this->trustHistory->append([
                'id'             => (string) Uuid::uuid7(),
                'device_id'      => $deviceId,
                'user_id'        => $userId,
                'from_status'    => $fromStatus,
                'to_status'      => 'revoked',
                'actor_id'       => $actorId,
                'actor_type'     => $actorType,
                'reason'         => $isAdmin ? 'admin_removal' : 'user_removal',
                'correlation_id' => $correlationId,
                'created_at'     => now(),
            ]);

            $sessionsRevoked = $this->sessions->revokeAllForDevice(
                deviceId:      $deviceId,
                actorType:     $actorType,
                correlationId: $correlationId,
                ipHash:        $ipHash,
            );

            $this->events->publish(new DeviceRemovedEvent(
                deviceId:       $deviceId,
                actorId:        $actorId,
                correlationId:  $correlationId,
                userId:         $userId,
                sessionsRevoked: $sessionsRevoked,
                removedByAdmin:  $isAdmin,
                adminActorId:    $adminActorId,
            ));

            $this->audit->record(new AuditEventData(
                action:        'identity.device.removed',
                actorType:     $actorType,
                resourceType:  'identity.device',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $actorId,
                resourceId:    $deviceId,
                beforeState:   ['status' => $fromStatus],
                afterState:    ['status' => 'revoked', 'sessions_revoked' => $sessionsRevoked],
                ipAddress:     $ipHash,
                metadata:      ['subject_user_id' => $userId],
            ));
        });

        $this->metrics->incrementDeviceRemoved($actorType);

        return $sessionsRevoked;
    }

    /**
     * Block a device. Returns count of sessions revoked.
     */
    public function blockDevice(
        string  $deviceId,
        string  $userId,
        ?string $reason,
        bool    $isAdmin,
        ?string $adminActorId,
        string  $actorType,
        string  $correlationId,
        string  $ipHash,
    ): int {
        $sessionsRevoked = 0;

        DB::connection('platform')->transaction(function () use ($deviceId, $userId, $reason, $isAdmin, $adminActorId, $actorType, $correlationId, $ipHash, &$sessionsRevoked): void {
            // T134: lock the device row to prevent concurrent status transitions
            \App\Modules\Identity\Infrastructure\Models\Device::where('id', $deviceId)->lockForUpdate()->firstOrFail();

            $device     = $this->devices->findById($deviceId);
            $fromStatus = $device?->status ?? 'unknown';
            $actorId    = $isAdmin ? ($adminActorId ?? $userId) : $userId;
            $blockedAt  = now()->toIso8601String();

            $this->devices->blockDevice($deviceId, $blockedAt, $reason);

            $this->trustHistory->append([
                'id'             => (string) Uuid::uuid7(),
                'device_id'      => $deviceId,
                'user_id'        => $userId,
                'from_status'    => $fromStatus,
                'to_status'      => 'blocked',
                'actor_id'       => $actorId,
                'actor_type'     => $actorType,
                'reason'         => $reason,
                'correlation_id' => $correlationId,
                'created_at'     => now(),
            ]);

            $sessionsRevoked = $this->sessions->revokeAllForDevice(
                deviceId:      $deviceId,
                actorType:     $actorType,
                correlationId: $correlationId,
                ipHash:        $ipHash,
            );

            $this->events->publish(new DeviceBlockedEvent(
                deviceId:       $deviceId,
                actorId:        $actorId,
                correlationId:  $correlationId,
                userId:         $userId,
                reason:         $reason,
                sessionsRevoked: $sessionsRevoked,
                blockedByAdmin:  $isAdmin,
                adminActorId:    $adminActorId,
            ));

            $this->audit->record(new AuditEventData(
                action:        'identity.device.blocked',
                actorType:     $actorType,
                resourceType:  'identity.device',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $actorId,
                resourceId:    $deviceId,
                beforeState:   ['status' => $fromStatus],
                afterState:    ['status' => 'blocked', 'sessions_revoked' => $sessionsRevoked],
                ipAddress:     $ipHash,
                metadata:      ['subject_user_id' => $userId],
            ));
        });

        $this->metrics->incrementDeviceBlocked($actorType);

        return $sessionsRevoked;
    }

    public function unblockDevice(
        string $deviceId,
        string $adminActorId,
        string $reason,
        string $correlationId,
        string $ipHash,
    ): void {
        DB::connection('platform')->transaction(function () use ($deviceId, $adminActorId, $reason, $correlationId, $ipHash): void {
            $device     = $this->devices->findById($deviceId);
            $fromStatus = $device?->status ?? 'blocked';
            $userId     = (string) ($device?->user_id ?? '');

            $this->devices->unblockDevice($deviceId);

            $this->trustHistory->append([
                'id'             => (string) Uuid::uuid7(),
                'device_id'      => $deviceId,
                'user_id'        => $userId,
                'from_status'    => $fromStatus,
                'to_status'      => 'trusted',
                'actor_id'       => $adminActorId,
                'actor_type'     => 'admin',
                'reason'         => $reason,
                'correlation_id' => $correlationId,
                'created_at'     => now(),
            ]);

            $this->events->publish(new DeviceUnblockedEvent(
                deviceId:     $deviceId,
                actorId:      $adminActorId,
                correlationId: $correlationId,
                userId:       $userId,
                adminActorId: $adminActorId,
                reason:       $reason,
            ));

            $this->audit->record(new AuditEventData(
                action:        'identity.device.unblocked',
                actorType:     'admin',
                resourceType:  'identity.device',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $adminActorId,
                resourceId:    $deviceId,
                beforeState:   ['status' => $fromStatus],
                afterState:    ['status' => 'trusted'],
                ipAddress:     $ipHash,
                metadata:      ['subject_user_id' => $userId],
            ));
        });
    }
}
