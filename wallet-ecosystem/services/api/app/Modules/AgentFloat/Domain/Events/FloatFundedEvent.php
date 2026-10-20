<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class FloatFundedEvent extends BaseIntegrationEvent
{
    public function __construct(
        public readonly string $agentId,
        public readonly string $walletId,
        public readonly int    $amount,
        public readonly string $currencyCode,
        public readonly string $journalId,
        public readonly string $floatTransactionId,
        string $correlationId,
        ?string $actorId = null,
    ) {
        parent::__construct(
            aggregateId:   $walletId,
            actorId:       $actorId,
            correlationId: $correlationId,
        );
    }

    public function eventName(): string { return 'AgentFloat.Wallet.Funded'; }

    public function aggregateType(): string { return 'agent_float_wallet'; }

    public function payload(): array
    {
        return [
            'agent_id'             => $this->agentId,
            'wallet_id'            => $this->walletId,
            'amount'               => $this->amount,
            'currency_code'        => $this->currencyCode,
            'journal_id'           => $this->journalId,
            'float_transaction_id' => $this->floatTransactionId,
        ];
    }
}
