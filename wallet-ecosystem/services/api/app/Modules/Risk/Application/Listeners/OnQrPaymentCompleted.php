<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Listeners;

use App\Modules\Qr\Domain\Events\QrPaymentCompletedEvent;
use App\Modules\Risk\Application\Services\VelocityAccumulatorService;

final class OnQrPaymentCompleted
{
    public function __construct(private readonly VelocityAccumulatorService $velocity) {}

    public function handle(QrPaymentCompletedEvent $event): void
    {
        $amount = (string) ($event->amount / 100);

        $key = $this->velocity->buildKey($event->customerId, 'qr_payment', 86400);
        $this->velocity->record($key, $amount, 86400);
    }
}
