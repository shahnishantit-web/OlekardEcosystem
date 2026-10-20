<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class FloatLowAlertEvent extends BaseIntegrationEvent
{
    public function __construct(
        public readonly string $agentId,
        public readonly string $walletId,
        public readonly int    $currentBalance,
        public readonly int    $threshold,
        public readonly string $currencyCode,
        string $correlationId,
    ) {
        parent::__construct(
            aggregateId:   $walletId,
            actorId:       null,
            correlationId: $correlationId,
        );
    }

    public function eventName(): string { return 'AgentFloat.Wallet.LowAlert'; }

    public function aggregateType(): string { return 'agent_float_wallet'; }

    public function payload(): array
    {
        return [
            'agent_id'        => $this->agentId,
            'wallet_id'       => $this->walletId,
            'current_balance' => $this->currentBalance,
            'threshold'       => $this->threshold,
            'currency_code'   => $this->currencyCode,
        ];
    }
}
