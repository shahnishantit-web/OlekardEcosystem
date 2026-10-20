<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Listeners;

use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Service\Domain\Events\ServicePaymentCompletedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;

final class BillPaymentCompletedListener implements ShouldQueue
{
    use \Illuminate\Bus\Queueable;

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
        $this->onQueue('notifications-critical');
    }

    public function handle(ServicePaymentCompletedEvent $event): void
    {
        $amount = number_format($event->amount / 100, 2);

        $this->notificationService->createFromEvent(new CreateNotificationCommand(
            sourceEventId:    $event->transactionId,
            sourceEventType:  ServicePaymentCompletedEvent::class,
            notificationType: 'payment.bill.paid',
            category:         NotificationCategory::Financial,
            mandatory:        true,
            recipientId:      $event->customerId,
            variables:        [
                'amount'    => $amount,
                'currency'  => $event->currencyCode,
                'biller'    => $event->serviceId,
                'reference' => $event->receiptReference,
            ],
            correlationId:    $event->correlationId(),
        ));
    }
}
