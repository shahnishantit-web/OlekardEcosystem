<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class WalletLimitsConfiguredEvent extends BaseIntegrationEvent
{
    /**
     * @param array<array{limitType: string, oldValue: int|null, newValue: int|null}> $changes
     */
    public function __construct(
        string $walletId,
        private readonly array $changes,
        ?string $actorId,
        string $correlationId,
    ) {
        parent::__construct($walletId, $actorId, $correlationId);
    }

    public function eventName(): string
    {
        return 'Wallet.Limits.Configured';
    }

    public function aggregateType(): string
    {
        return 'Wallet';
    }

    public function payload(): array
    {
        return [
            'wallet_id'      => $this->aggregateId(),
            'changes'        => $this->changes,
            'actor_id'       => $this->actorId(),
            'correlation_id' => $this->correlationId(),
        ];
    }
}
