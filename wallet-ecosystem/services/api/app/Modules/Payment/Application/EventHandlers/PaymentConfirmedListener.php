<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\EventHandlers;

use App\Modules\Notification\Application\Commands\CreatePaymentNotificationCommand;
use App\Modules\Notification\Domain\Contracts\PaymentNotificationServiceInterface;
use App\Modules\Platform\Domain\Contracts\OutboxVersionCheckerInterface;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

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

        // FR-006/FR-007: insertOrIgnore provides at-most-once effect per unique key.
        // payment_allocations: idempotency_key unique constraint.
        DB::connection('payment')->table('payment.payment_allocations')->insertOrIgnore([
            'id'                  => (string) Uuid::uuid7(),
            'payment_intent_id'   => $payload['payment_intent_id'] ?? '',
            'allocation_type'     => 'payment',
            'recipient_type'      => 'merchant',
            'recipient_reference' => $payload['purpose_reference'] ?? '',
            'amount_minor'        => (int) ($payload['amount_minor'] ?? 0),
            'currency_code'       => $payload['currency_code'] ?? '',
            'status'              => 'pending',
            'idempotency_key'     => $payload['allocation_reference'] ?? ($payload['payment_intent_id'] ?? ''),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        // notifications: (source_event_id, notification_type) unique constraint.
        $this->notificationService->createFromPaymentEvent(new CreatePaymentNotificationCommand(
            notificationType: 'payment_confirmed',
            sourceEventId:    $eventId ?? ($payload['payment_intent_id'] ?? ''),
            payerActorId:     $payload['payer_actor_id'] ?? '',
            correlationId:    $payload['correlation_id'] ?? null,
            amountMinor:      (int) ($payload['amount_minor'] ?? 0),
            currencyCode:     $payload['currency_code'] ?? '',
        ));
    }
}
