<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Listeners;

use App\Modules\Funding\Domain\Events\FundingTransactionCompleted;
use App\Modules\Risk\Application\Services\VelocityAccumulatorService;

final class OnFundingTransactionCompleted
{
    public function __construct(private readonly VelocityAccumulatorService $velocity) {}

    public function handle(FundingTransactionCompleted $event): void
    {
        $key = $this->velocity->buildKey($event->walletId, 'funding', 86400);
        $this->velocity->record($key, (string) ($event->netAmount / 100), 86400);

        $monthKey = $this->velocity->buildKey($event->walletId, 'funding', 2592000);
        $this->velocity->record($monthKey, (string) ($event->netAmount / 100), 2592000);
    }
}
