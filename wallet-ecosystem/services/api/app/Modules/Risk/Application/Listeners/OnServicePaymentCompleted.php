<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Listeners;

use App\Modules\Risk\Application\Services\VelocityAccumulatorService;
use App\Modules\Service\Domain\Events\ServicePaymentCompletedEvent;

final class OnServicePaymentCompleted
{
    public function __construct(private readonly VelocityAccumulatorService $velocity) {}

    public function handle(ServicePaymentCompletedEvent $event): void
    {
        $amount = (string) ($event->amount / 100);

        $key = $this->velocity->buildKey($event->customerId, 'bill_payment', 86400);
        $this->velocity->record($key, $amount, 86400);
    }
}
