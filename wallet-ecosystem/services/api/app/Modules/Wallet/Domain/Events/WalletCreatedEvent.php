<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class WalletCreatedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $walletId,
        private readonly string $ownerId,
        ?string $actorId,
        string $correlationId,
    ) {
        parent::__construct($walletId, $actorId, $correlationId);
    }

    public function eventName(): string
    {
        return 'Wallet.Created';
    }

    public function aggregateType(): string
    {
        return 'Wallet';
    }

    public function payload(): array
    {
        return [
            'wallet_id'      => $this->aggregateId(),
            'owner_id'       => $this->ownerId,
            'actor_id'       => $this->actorId(),
            'correlation_id' => $this->correlationId(),
        ];
    }
}
