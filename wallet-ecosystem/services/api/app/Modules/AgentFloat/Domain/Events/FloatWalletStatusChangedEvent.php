<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class FloatWalletStatusChangedEvent extends BaseIntegrationEvent
{
    public function __construct(
        public readonly string  $agentId,
        public readonly string  $walletId,
        public readonly string  $oldStatus,
        public readonly string  $newStatus,
        public readonly ?string $triggeredBy,
        string $correlationId,
    ) {
        parent::__construct(
            aggregateId:   $walletId,
            actorId:       $triggeredBy,
            correlationId: $correlationId,
        );
    }

    public function eventName(): string { return 'AgentFloat.Wallet.StatusChanged'; }

    public function aggregateType(): string { return 'agent_float_wallet'; }

    public function payload(): array
    {
        return [
            'agent_id'     => $this->agentId,
            'wallet_id'    => $this->walletId,
            'old_status'   => $this->oldStatus,
            'new_status'   => $this->newStatus,
            'triggered_by' => $this->triggeredBy,
        ];
    }
}
