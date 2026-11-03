<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\EventHandlers;

use App\Modules\Notification\Application\Commands\CreatePaymentNotificationCommand;
use App\Modules\Notification\Domain\Contracts\PaymentNotificationServiceInterface;
use App\Modules\Platform\Domain\Contracts\OutboxVersionCheckerInterface;

final class PaymentConfirmedListener
{
    public function __construct(
        private readonly PaymentNotificationServiceInterface $notificationService,
        private readonly OutboxVersionCheckerInterface $versionChecker,
    ) {}

    public function handle(array $payload): void
    {
        $eventId = $payload['eventId'] ?? null;

        // FR-010: dead-letter if outbox row carries an unsupported schema version.
        if ($this->versionChecker->checkAndDeadLetterIfUnsupported($eventId, ['1'])) {
            return;
        }

        // Confirmation does not define a financial allocation. Keep allocation
        // creation in the shared accounting/settlement flow, where approved
        // recipient and split rules are available. Writing a synthetic "payment"
        // allocation here violates the allocation schema and invents a recipient.

        // notifications: (source_event_id, notification_type) unique constraint.
        $this->notificationService->createFromPaymentEvent(new CreatePaymentNotificationCommand(
            notificationType: 'payment_confirmed',
            sourceEventId: $eventId ?? ($payload['payment_intent_id'] ?? ''),
            payerActorId: $payload['payer_actor_id'] ?? '',
            correlationId: $payload['correlation_id'] ?? null,
            amountMinor: (int) ($payload['amount_minor'] ?? 0),
            currencyCode: $payload['currency_code'] ?? '',
        ));
    }
}
