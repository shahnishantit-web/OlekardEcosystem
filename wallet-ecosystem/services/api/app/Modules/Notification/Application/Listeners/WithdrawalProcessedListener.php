<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Listeners;

use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use App\Modules\Withdrawal\Domain\Events\WithdrawalCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

final class WithdrawalProcessedListener implements ShouldQueue
{
    use \Illuminate\Bus\Queueable;

    public function __construct(
        private readonly NotificationService  $notificationService,
        private readonly WalletModuleContract $wallet,
    ) {
        $this->onQueue('notifications-critical');
    }

    public function handle(WithdrawalCompleted $event): void
    {
        $userId = $this->wallet->ownerActorId($event->walletId);

        if ($userId === null) {
            Log::warning('notification.withdrawal.wallet_owner_not_found', [
                'wallet_id'    => $event->walletId,
                'withdrawal_id' => $event->withdrawalTransactionId,
            ]);

            return;
        }

        $amount = number_format($event->grossAmount / 100, 2);

        $this->notificationService->createFromEvent(new CreateNotificationCommand(
            sourceEventId:    $event->withdrawalTransactionId,
            sourceEventType:  WithdrawalCompleted::class,
            notificationType: 'payment.withdrawal.processed',
            category:         NotificationCategory::Financial,
            mandatory:        true,
            recipientId:      $userId,
            variables:        [
                'amount'    => $amount,
                'currency'  => $event->currencyCode,
                'reference' => $event->withdrawalTransactionId,
            ],
            correlationId:    $event->correlationId(),
        ));
    }
}
