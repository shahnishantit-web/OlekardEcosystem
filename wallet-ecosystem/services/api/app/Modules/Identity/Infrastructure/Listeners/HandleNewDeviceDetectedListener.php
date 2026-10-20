<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Listeners;

use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceSecurityEventRepositoryInterface;
use App\Modules\Identity\Domain\Events\DeviceNewDeviceNotificationSentEvent;
use App\Modules\Identity\Domain\Events\NewDeviceDetectedEvent;
use App\Modules\Identity\Domain\Services\IdentityMetrics;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class HandleNewDeviceDetectedListener
{
    public function __construct(
        private readonly DeviceSecurityEventRepositoryInterface $securityEvents,
        private readonly EventPublisherInterface               $events,
        private readonly AuditServiceInterface                 $audit,
        private readonly ConfigurationServiceInterface         $config,
        private readonly IdentityMetrics                       $metrics,
        private readonly DeviceRepositoryInterface             $devices,
    ) {}

    public function handle(NewDeviceDetectedEvent $event): void
    {
        try {
            $payload  = $event->payload();
            $deviceId = $payload['device_id'] ?? '';
            $userId   = $payload['user_id'] ?? '';

            if ($deviceId === '' || $userId === '') {
                return;
            }

            // Idempotency check: skip if security event already exists for this device + new_device_notified
            $existing = $this->securityEvents->findPendingForDevice($deviceId);
            if ($existing !== null) {
                return;
            }

            $notificationEnabled = $this->config->getOrDefault('device.new_device_notification_enabled', 'true');
            if ($notificationEnabled !== 'true') {
                return;
            }

            $device = $this->devices->findById($deviceId);
            $deviceName = $device?->device_name ?? 'Unknown Device';
            $platform   = $device?->platform ?? 'unknown';

            $ttl                   = (int) $this->config->getOrDefault('device.new_device_acknowledgment_ttl', '86400');
            $acknowledgmentExpires = now()->addSeconds($ttl);
            $correlationId         = $event->correlationId();

            DB::connection('platform')->transaction(function () use ($deviceId, $userId, $acknowledgmentExpires, $correlationId, $deviceName, $platform): void {
                $this->securityEvents->create([
                    'id'                        => (string) Uuid::uuid7(),
                    'device_id'                 => $deviceId,
                    'user_id'                   => $userId,
                    'event_type'                => 'new_device_notified',
                    'acknowledgment_expires_at' => $acknowledgmentExpires,
                    'correlation_id'            => $correlationId,
                ]);

                $this->events->publish(new DeviceNewDeviceNotificationSentEvent(
                    deviceId:               $deviceId,
                    actorId:                null,
                    correlationId:          $correlationId,
                    userId:                 $userId,
                    acknowledgmentExpiresAt: $acknowledgmentExpires->toIso8601String(),
                    deviceName:             $deviceName,
                    platform:               $platform,
                ));

                $this->audit->record(new AuditEventData(
                    action:        'identity.device.new-device-notified',
                    actorType:     'system',
                    resourceType:  'identity.device',
                    correlationId: $correlationId,
                    outcome:       'success',
                    resourceId:    $deviceId,
                    afterState:    ['user_id' => $userId, 'acknowledgment_expires_at' => $acknowledgmentExpires->toIso8601String()],
                ));
            });

            $this->metrics->incrementNewDeviceNotificationSent();
        } catch (\Throwable $e) {
            // Non-blocking: delivery failure must not break authentication (FR-E03-034)
            Log::error('HandleNewDeviceDetectedListener failed', [
                'error'     => $e->getMessage(),
                'device_id' => $event->payload()['device_id'] ?? 'unknown',
            ]);
            $this->metrics->incrementNewDeviceNotificationFailed();
        }
    }
}
