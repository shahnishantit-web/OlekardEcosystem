<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\EventHandlers;

use App\Modules\Notification\Application\Commands\CreatePaymentNotificationCommand;
use App\Modules\Notification\Domain\Contracts\PaymentNotificationServiceInterface;
use App\Modules\Platform\Domain\Contracts\OutboxVersionCheckerInterface;

final class PaymentFailedListener
{
    public function __construct(
        private readonly PaymentNotificationServiceInterface $notificationService,
        private readonly OutboxVersionCheckerInterface $versionChecker,
    ) {}

    public function handle(array $payload): void
    {
        $eventId = $payload['eventId'] ?? null;

        if ($this->versionChecker->checkAndDeadLetterIfUnsupported($eventId, ['1'])) {
            return;
        }

        // FR-007: insertOrIgnore dedup via (source_event_id, notification_type) unique constraint.
        $this->notificationService->createFromPaymentEvent(new CreatePaymentNotificationCommand(
            notificationType: 'payment_failed',
            sourceEventId:    $eventId ?? ($payload['payment_intent_id'] ?? ''),
            payerActorId:     $payload['payer_actor_id'] ?? '',
            correlationId:    $payload['correlation_id'] ?? null,
            amountMinor:      0,
            currencyCode:     '',
        ));
    }
}
