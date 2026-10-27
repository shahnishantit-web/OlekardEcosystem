<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Listeners;

use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Payment\Domain\Events\PaymentConfirmedEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class PaymentConfirmedReceiptListener implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
        $this->onQueue('notifications-critical');
    }

    public function handle(PaymentConfirmedEvent $event): void
    {
        $idempotencyKey = $event->eventName() . '.' . $event->correlationId();
        $paymentIntentId = $event->aggregateId();
        $eventData       = $event->toArray();
        $payerActorId    = $eventData['payer_actor_id'] ?? null;

        if ($payerActorId === null) {
            Log::warning('notification.receipt.payment_confirmed.no_payer', [
                'payment_intent_id' => $paymentIntentId,
                'correlation_id'    => $event->correlationId(),
            ]);

            return;
        }

        $affected = DB::connection('payment')
            ->table('payment.receipt_notification_deliveries')
            ->insertOrIgnore([
                'id'                     => (string) Uuid::uuid7(),
                'event_idempotency_key'  => $idempotencyKey,
                'event_type'             => $event->eventName(),
                'payment_intent_id'      => $paymentIntentId,
                'status'                 => 'dispatched',
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

        if ($affected === 0) {
            Log::info('notification.receipt.payment_confirmed.duplicate_skipped', [
                'idempotency_key'   => $idempotencyKey,
                'payment_intent_id' => $paymentIntentId,
            ]);

            return;
        }

        try {
            $amount = number_format(($eventData['amount_minor'] ?? 0) / 100, 2);

            $this->notificationService->createFromEvent(new CreateNotificationCommand(
                sourceEventId:    $paymentIntentId,
                sourceEventType:  PaymentConfirmedEvent::class,
                notificationType: 'payment.receipt.payment_confirmed',
                category:         NotificationCategory::Financial,
                mandatory:        true,
                recipientId:      $payerActorId,
                variables:        [
                    'amount'          => $amount,
                    'currency'        => $eventData['currency_code'] ?? '',
                    'payment_reference' => $paymentIntentId,
                    'provider'        => $eventData['provider_code'] ?? '',
                ],
                correlationId:    $event->correlationId(),
            ));
        } catch (\Throwable $e) {
            Log::error('notification.receipt.payment_confirmed.dispatch_failed', [
                'payment_intent_id' => $paymentIntentId,
                'correlation_id'    => $event->correlationId(),
                'error'             => $e->getMessage(),
            ]);

            DB::connection('payment')
                ->table('payment.receipt_notification_deliveries')
                ->where('event_idempotency_key', $idempotencyKey)
                ->update(['status' => 'failed', 'updated_at' => now()]);
        }
    }
}
