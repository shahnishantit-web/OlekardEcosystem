<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Listeners;

use App\Modules\Commission\Domain\Events\CommissionPaidEvent;
use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use Illuminate\Contracts\Queue\ShouldQueue;

final class CommissionCreditedListener implements ShouldQueue
{
    use \Illuminate\Bus\Queueable;

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
        $this->onQueue('notifications-critical');
    }

    public function handle(CommissionPaidEvent $event): void
    {
        $amount = number_format($event->amount / 100, 2);

        $this->notificationService->createFromEvent(new CreateNotificationCommand(
            sourceEventId:    $event->commissionId,
            sourceEventType:  CommissionPaidEvent::class,
            notificationType: 'payment.commission.credited',
            category:         NotificationCategory::Financial,
            mandatory:        true,
            recipientId:      $event->agentId,
            variables:        [
                'amount'    => $amount,
                'currency'  => $event->currencyCode,
                'period'    => now()->format('Y-m'),
                'reference' => $event->payoutId,
            ],
            correlationId:    $event->correlationId(),
        ));
    }
}
