<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Platform\Domain\Events\TransactionReversedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * E27 T076 — Notifies the wallet owner when their transaction is reversed by orphan auto-recovery.
 *
 * Registered in PlatformServiceProvider for TransactionReversedEvent.
 */
final class NotifyCustomerOnReversalListener implements ShouldQueue
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(TransactionReversedEvent $event): void
    {
        Log::withContext(['correlation_id' => $event->correlationId]);

        try {
            $ownerActorId = DB::connection('wallet')->table('wallet.wallets')
                ->where('id', $event->walletId)->value('owner_id');
            if (! is_string($ownerActorId) || $ownerActorId === '') {
                Log::warning('platform.transaction_reversed.notification_owner_not_found', [
                    'transaction_id' => $event->transactionId,
                    'correlation_id' => $event->correlationId,
                ]);

                return;
            }

            $this->notificationService->createFromEvent(new CreateNotificationCommand(
                sourceEventId:    $event->transactionId,
                sourceEventType:  TransactionReversedEvent::class,
                notificationType: 'transaction.reversed',
                category:         NotificationCategory::Financial,
                mandatory:        true,
                recipientId:      $ownerActorId,
                variables:        [
                    'transaction_id' => $event->transactionId,
                    'module_type'    => $event->moduleType,
                    'amount'         => $event->amount,
                    'currency'       => $event->currency,
                    'reversed_at'    => $event->reversedAt,
                ],
                correlationId: $event->correlationId,
            ));
        } catch (\Throwable $e) {
            // Log and swallow — notification failure must not block the reversal audit trail.
            Log::warning('platform.transaction_reversed.notification_failed', [
                'transaction_id' => $event->transactionId,
                'module_type'    => $event->moduleType,
                'error'          => $e->getMessage(),
                'correlation_id' => $event->correlationId,
            ]);
        }
    }
}
