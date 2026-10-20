<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class WalletHoldExpiredEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $walletId,
        private readonly string $holdId,
        private readonly string $pocketId,
        private readonly int $amount,
        private readonly string $expiredAt,
        string $correlationId,
    ) {
        parent::__construct($walletId, 'system', $correlationId);
    }

    public function eventName(): string
    {
        return 'Wallet.Hold.Expired';
    }

    public function aggregateType(): string
    {
        return 'Wallet';
    }

    public function payload(): array
    {
        return [
            'wallet_id'      => $this->aggregateId(),
            'hold_id'        => $this->holdId,
            'pocket_id'      => $this->pocketId,
            'amount'         => $this->amount,
            'expired_at'     => $this->expiredAt,
            'actor_id'       => 'system',
            'correlation_id' => $this->correlationId(),
        ];
    }
}
