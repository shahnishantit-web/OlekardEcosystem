<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Listeners;

use App\Modules\Risk\Application\Services\VelocityAccumulatorService;
use App\Modules\Transfer\Domain\Events\TransferCompleted;

final class OnTransferCompleted
{
    public function __construct(private readonly VelocityAccumulatorService $velocity) {}

    public function handle(TransferCompleted $event): void
    {
        $amount = (string) ($event->netAmount / 100);

        foreach ([$event->senderWalletId, $event->recipientWalletId] as $walletId) {
            $key = $this->velocity->buildKey($walletId, 'transfer', 86400);
            $this->velocity->record($key, $amount, 86400);
        }
    }
}
