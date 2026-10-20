<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Listeners;

use App\Modules\Identity\Domain\Events\DeviceSuspiciousDetectedEvent;
use App\Modules\Identity\Domain\Events\NewDeviceDetectedEvent;
use App\Modules\Identity\Domain\Events\PinChangedEvent;
use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Handles E03 security events and dispatches the appropriate security notification.
 *
 * Registered for:
 *   - NewDeviceDetectedEvent    → security.new_device
 *   - PinChangedEvent           → security.password_changed
 *   - DeviceSuspiciousDetectedEvent → security.suspicious_activity
 */
final class SecurityAlertListener implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
        $this->onQueue('notifications-critical');
    }

    public function handle(object $event): void
    {
        if ($event instanceof NewDeviceDetectedEvent) {
            $this->handleNewDevice($event);
        } elseif ($event instanceof PinChangedEvent) {
            $this->handlePinChanged($event);
        } elseif ($event instanceof DeviceSuspiciousDetectedEvent) {
            $this->handleSuspiciousActivity($event);
        } else {
            Log::warning('notification.security.unhandled_event', ['event_class' => $event::class]);
        }
    }

    private function handleNewDevice(NewDeviceDetectedEvent $event): void
    {
        $payload = $event->payload();

        $this->notificationService->createFromEvent(new CreateNotificationCommand(
            sourceEventId:    $event->correlationId(),
            sourceEventType:  NewDeviceDetectedEvent::class,
            notificationType: 'security.new_device',
            category:         NotificationCategory::Security,
            mandatory:        true,
            recipientId:      $event->aggregateId(),
            variables:        [
                'device_name' => $payload['masked_identifier'] ?? '[Unknown]',
                'platform'    => 'mobile',
                'occurred_at' => now()->toIso8601String(),
            ],
            correlationId:    $event->correlationId(),
        ));
    }

    private function handlePinChanged(PinChangedEvent $event): void
    {
        $this->notificationService->createFromEvent(new CreateNotificationCommand(
            sourceEventId:    $event->correlationId(),
            sourceEventType:  PinChangedEvent::class,
            notificationType: 'security.password_changed',
            category:         NotificationCategory::Security,
            mandatory:        true,
            recipientId:      $event->aggregateId(),
            variables:        [
                'occurred_at' => now()->toIso8601String(),
                'support_ref' => 'SUPPORT-' . strtoupper(substr($event->correlationId(), 0, 8)),
            ],
            correlationId:    $event->correlationId(),
        ));
    }

    private function handleSuspiciousActivity(DeviceSuspiciousDetectedEvent $event): void
    {
        $payload = $event->payload();
        $userId  = $payload['user_id'] ?? null;

        if ($userId === null) {
            Log::error('notification.security.suspicious_missing_user_id', [
                'correlation_id' => $event->correlationId(),
            ]);

            return;
        }

        $riskSignals = $payload['risk_signals'] ?? [];
        $detail      = is_array($riskSignals) ? implode(', ', $riskSignals) : (string) $riskSignals;

        $this->notificationService->createFromEvent(new CreateNotificationCommand(
            sourceEventId:    $event->correlationId(),
            sourceEventType:  DeviceSuspiciousDetectedEvent::class,
            notificationType: 'security.suspicious_activity',
            category:         NotificationCategory::Security,
            mandatory:        true,
            recipientId:      $userId,
            variables:        [
                'detail'      => $detail ?: 'Suspicious login detected',
                'occurred_at' => now()->toIso8601String(),
                'support_ref' => 'SUPPORT-' . strtoupper(substr($event->correlationId(), 0, 8)),
            ],
            correlationId:    $event->correlationId(),
        ));
    }
}
