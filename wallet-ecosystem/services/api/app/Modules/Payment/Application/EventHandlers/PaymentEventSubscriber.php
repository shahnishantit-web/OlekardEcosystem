<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\EventHandlers;

use Illuminate\Events\Dispatcher;

final class PaymentEventSubscriber
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen('payment.confirmed', PaymentConfirmedListener::class);
        $events->listen('payment.failed',    PaymentFailedListener::class);
        $events->listen('payment.reversed',  PaymentReversedListener::class);
        $events->listen('refund.confirmed',  RefundConfirmedListener::class);
    }
}
