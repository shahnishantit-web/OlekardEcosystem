<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Listeners;

use App\Modules\Risk\Application\Services\VelocityAccumulatorService;
use App\Modules\Withdrawal\Domain\Events\WithdrawalCompleted;

final class OnWithdrawalCompleted
{
    public function __construct(private readonly VelocityAccumulatorService $velocity) {}

    public function handle(WithdrawalCompleted $event): void
    {
        $amount = (string) ($event->netPayoutAmount / 100);

        $key = $this->velocity->buildKey($event->walletId, 'withdrawal', 86400);
        $this->velocity->record($key, $amount, 86400);
    }
}
