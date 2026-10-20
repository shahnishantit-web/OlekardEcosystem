<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class WalletClosedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $walletId,
        private readonly ?string $reason,
        ?string $actorId,
        string $correlationId,
    ) {
        parent::__construct($walletId, $actorId, $correlationId);
    }

    public function eventName(): string
    {
        return 'Wallet.Closed';
    }

    public function aggregateType(): string
    {
        return 'Wallet';
    }

    public function payload(): array
    {
        return [
            'wallet_id'      => $this->aggregateId(),
            'reason'         => $this->reason,
            'actor_id'       => $this->actorId(),
            'correlation_id' => $this->correlationId(),
        ];
    }
}
