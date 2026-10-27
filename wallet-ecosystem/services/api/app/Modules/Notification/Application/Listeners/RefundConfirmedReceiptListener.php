<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Listeners;

use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Payment\Domain\Events\RefundConfirmedEvent;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class RefundConfirmedReceiptListener implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
        $this->onQueue('notifications-critical');
    }

    public function handle(RefundConfirmedEvent $event): void
    {
        $idempotencyKey  = $event->eventName() . '.' . $event->correlationId();
        $paymentIntentId = $event->aggregateId();

        // RefundConfirmedEvent does not carry payer_actor_id — resolve from DB (FR-015)
        $payerActorId = PaymentIntentModel::find($paymentIntentId)?->payer_actor_id;

        if ($payerActorId === null) {
            Log::warning('notification.receipt.refund_confirmed.payer_not_found', [
                'payment_intent_id' => $paymentIntentId,
                'correlation_id'    => $event->correlationId(),
            ]);

            // Do not throw — delivery failure must NOT reverse the refund (FR-015)
            return;
        }

        $affected = DB::connection('payment')
            ->table('payment.receipt_notification_deliveries')
            ->insertOrIgnore([
                'id'                    => (string) Uuid::uuid7(),
                'event_idempotency_key' => $idempotencyKey,
                'event_type'            => $event->eventName(),
                'payment_intent_id'     => $paymentIntentId,
                'status'                => 'dispatched',
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);

        if ($affected === 0) {
            Log::info('notification.receipt.refund_confirmed.duplicate_skipped', [
                'idempotency_key'   => $idempotencyKey,
                'payment_intent_id' => $paymentIntentId,
            ]);

            return;
        }

        $eventData = $event->toArray();

        try {
            $refundAmount = number_format(($eventData['refund_amount_minor'] ?? 0) / 100, 2);

            $this->notificationService->createFromEvent(new CreateNotificationCommand(
                sourceEventId:    $paymentIntentId,
                sourceEventType:  RefundConfirmedEvent::class,
                notificationType: 'payment.receipt.refund_confirmed',
                category:         NotificationCategory::Financial,
                mandatory:        true,
                recipientId:      $payerActorId,
                variables:        [
                    'refund_amount'     => $refundAmount,
                    'currency'          => $eventData['currency_code'] ?? '',
                    'payment_reference' => $paymentIntentId,
                    'is_full_refund'    => $eventData['is_full_refund'] ? 'yes' : 'no',
                ],
                correlationId:    $event->correlationId(),
            ));
        } catch (\Throwable $e) {
            Log::error('notification.receipt.refund_confirmed.dispatch_failed', [
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
