<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Listeners;

use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Qr\Domain\Events\QrPaymentCompletedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;

final class QrPaymentCompletedListener implements ShouldQueue
{
    use \Illuminate\Bus\Queueable;

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
        $this->onQueue('notifications-critical');
    }

    public function handle(QrPaymentCompletedEvent $event): void
    {
        $amount = number_format($event->amount / 100, 2);

        // Merchant: payment received
        $this->notificationService->createFromEvent(new CreateNotificationCommand(
            sourceEventId:    $event->paymentReference,
            sourceEventType:  QrPaymentCompletedEvent::class,
            notificationType: 'payment.qr.received',
            category:         NotificationCategory::Financial,
            mandatory:        true,
            recipientId:      $event->merchantId,
            variables:        [
                'amount'       => $amount,
                'currency'     => $event->currency,
                'customer_ref' => substr($event->customerId, 0, 8),
                'payment_ref'  => $event->paymentReference,
            ],
            correlationId:    $event->correlationId(),
        ));

        // Customer: payment sent
        $this->notificationService->createFromEvent(new CreateNotificationCommand(
            sourceEventId:    $event->paymentReference,
            sourceEventType:  QrPaymentCompletedEvent::class,
            notificationType: 'payment.qr.sent',
            category:         NotificationCategory::Financial,
            mandatory:        true,
            recipientId:      $event->customerId,
            variables:        [
                'amount'        => $amount,
                'currency'      => $event->currency,
                'merchant_name' => substr($event->merchantId, 0, 8),
                'payment_ref'   => $event->paymentReference,
            ],
            correlationId:    $event->correlationId(),
        ));
    }
}
