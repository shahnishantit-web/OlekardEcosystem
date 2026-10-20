<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Listeners;

use App\Modules\Funding\Domain\Events\FundingTransactionCompleted;
use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

final class FundingCompletedListener implements ShouldQueue
{
    use \Illuminate\Bus\Queueable;

    public function __construct(
        private readonly NotificationService  $notificationService,
        private readonly WalletModuleContract $wallet,
    ) {
        $this->onQueue('notifications-critical');
    }

    public function handle(FundingTransactionCompleted $event): void
    {
        $userId = $this->wallet->ownerActorId($event->walletId);

        if ($userId === null) {
            Log::warning('notification.funding.wallet_owner_not_found', [
                'wallet_id'    => $event->walletId,
                'event_id'     => $event->fundingTransactionId,
                'correlation_id' => $event->correlationId(),
            ]);

            return;
        }

        $amount = number_format($event->amount / 100, 2);

        $this->notificationService->createFromEvent(new CreateNotificationCommand(
            sourceEventId:    $event->fundingTransactionId,
            sourceEventType:  FundingTransactionCompleted::class,
            notificationType: 'payment.funding.received',
            category:         NotificationCategory::Financial,
            mandatory:        true,
            recipientId:      $userId,
            variables:        [
                'amount'    => $amount,
                'currency'  => $event->currencyCode,
                'source'    => 'bank transfer',
                'reference' => $event->fundingTransactionId,
            ],
            correlationId:    $event->correlationId(),
        ));
    }
}
