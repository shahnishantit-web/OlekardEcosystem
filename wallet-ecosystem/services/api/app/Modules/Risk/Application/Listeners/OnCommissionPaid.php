<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Listeners;

use App\Modules\Commission\Domain\Events\CommissionPaidEvent;
use App\Modules\Risk\Application\Services\VelocityAccumulatorService;

final class OnCommissionPaid
{
    public function __construct(private readonly VelocityAccumulatorService $velocity) {}

    public function handle(CommissionPaidEvent $event): void
    {
        $amount = (string) ($event->amount / 100);

        $key = $this->velocity->buildKey($event->agentId, 'commission', 86400);
        $this->velocity->record($key, $amount, 86400);
    }
}
