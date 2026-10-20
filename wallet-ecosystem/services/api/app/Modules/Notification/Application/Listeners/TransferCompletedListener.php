<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Listeners;

use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Transfer\Domain\Events\TransferCompleted;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

final class TransferCompletedListener implements ShouldQueue
{
    use \Illuminate\Bus\Queueable;

    public function __construct(
        private readonly NotificationService  $notificationService,
        private readonly WalletModuleContract $wallet,
    ) {
        $this->onQueue('notifications-critical');
    }

    public function handle(TransferCompleted $event): void
    {
        $senderId    = $this->wallet->ownerActorId($event->senderWalletId);
        $recipientId = $this->wallet->ownerActorId($event->recipientWalletId);
        $amount      = number_format($event->grossAmount / 100, 2);

        if ($senderId === null) {
            Log::warning('notification.transfer.sender_not_found', [
                'sender_wallet_id' => $event->senderWalletId,
                'transfer_id'      => $event->transferId,
            ]);
        } else {
            $this->notificationService->createFromEvent(new CreateNotificationCommand(
                sourceEventId:    $event->transferId,
                sourceEventType:  TransferCompleted::class,
                notificationType: 'payment.transfer.sent',
                category:         NotificationCategory::Financial,
                mandatory:        true,
                recipientId:      $senderId,
                variables:        [
                    'amount'        => $amount,
                    'currency'      => $event->currencyCode,
                    'recipient_ref' => $recipientId !== null ? substr($recipientId, 0, 8) : 'recipient',
                    'reference'     => $event->receiptReference,
                ],
                correlationId:    $event->correlationId(),
            ));
        }

        if ($recipientId === null) {
            Log::warning('notification.transfer.recipient_not_found', [
                'recipient_wallet_id' => $event->recipientWalletId,
                'transfer_id'         => $event->transferId,
            ]);
        } else {
            $this->notificationService->createFromEvent(new CreateNotificationCommand(
                sourceEventId:    $event->transferId,
                sourceEventType:  TransferCompleted::class,
                notificationType: 'payment.transfer.received',
                category:         NotificationCategory::Financial,
                mandatory:        true,
                recipientId:      $recipientId,
                variables:        [
                    'amount'     => $amount,
                    'currency'   => $event->currencyCode,
                    'sender_ref' => $senderId !== null ? substr($senderId, 0, 8) : 'sender',
                    'reference'  => $event->receiptReference,
                ],
                correlationId:    $event->correlationId(),
            ));
        }
    }
}
