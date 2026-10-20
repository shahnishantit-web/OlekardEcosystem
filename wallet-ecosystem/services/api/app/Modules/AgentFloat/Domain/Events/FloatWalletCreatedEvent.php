<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class FloatWalletCreatedEvent extends BaseIntegrationEvent
{
    public function __construct(
        public readonly string $agentId,
        public readonly string $walletId,
        public readonly string $ledgerAccountReference,
        public readonly string $currencyCode,
        string $correlationId,
        ?string $actorId = null,
    ) {
        parent::__construct(
            aggregateId:   $walletId,
            actorId:       $actorId,
            correlationId: $correlationId,
        );
    }

    public function eventName(): string { return 'AgentFloat.Wallet.Created'; }

    public function aggregateType(): string { return 'agent_float_wallet'; }

    public function payload(): array
    {
        return [
            'agent_id'                => $this->agentId,
            'wallet_id'               => $this->walletId,
            'ledger_account_reference' => $this->ledgerAccountReference,
            'currency_code'           => $this->currencyCode,
        ];
    }
}
