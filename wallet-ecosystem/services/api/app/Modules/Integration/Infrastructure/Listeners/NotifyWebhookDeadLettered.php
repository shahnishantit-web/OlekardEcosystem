<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Listeners;

use App\Modules\Integration\Domain\Events\WebhookEventDeadLetteredEvent;
use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use Illuminate\Support\Facades\Log;

final class NotifyWebhookDeadLettered
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function handle(WebhookEventDeadLetteredEvent $event): void
    {
        try {
            $this->notificationService->createFromEvent(new CreateNotificationCommand(
                sourceEventId:    $event->webhookEventId,
                sourceEventType:  'integration.webhook.dead_lettered',
                notificationType: 'integration.webhook.dead_lettered',
                category:         NotificationCategory::Operational,
                mandatory:        true,
                recipientId:      'system',
                variables:        [
                    'webhook_event_id' => $event->webhookEventId,
                    'provider_slug'    => $event->providerSlug,
                    'event_type'       => $event->eventType,
                    'retry_count'      => (string) $event->retryCount,
                ],
                correlationId:    $event->correlationId(),
            ));
        } catch (\Throwable $e) {
            Log::error('integration.dead_letter_notification_failed', [
                'webhook_event_id' => $event->webhookEventId,
                'error'            => $e->getMessage(),
            ]);
        }
    }
}
