<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class WalletStatusChangedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $walletId,
        private readonly string $fromStatus,
        private readonly string $toStatus,
        private readonly ?string $reason,
        ?string $actorId,
        string $correlationId,
    ) {
        parent::__construct($walletId, $actorId, $correlationId);
    }

    public function eventName(): string
    {
        return 'Wallet.Status.Changed';
    }

    public function aggregateType(): string
    {
        return 'Wallet';
    }

    public function payload(): array
    {
        return [
            'wallet_id'      => $this->aggregateId(),
            'from_status'    => $this->fromStatus,
            'to_status'      => $this->toStatus,
            'reason'         => $this->reason,
            'actor_id'       => $this->actorId(),
            'correlation_id' => $this->correlationId(),
        ];
    }
}
