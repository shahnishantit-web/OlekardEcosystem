<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

class CommissionCalculatedEvent extends BaseIntegrationEvent
{
    public function __construct(
        public readonly string $commissionId,
        public readonly string $agentId,
        public readonly string $transactionId,
        public readonly string $transactionType,
        public readonly int    $amount,
        public readonly string $currencyCode,
        public readonly string $ruleId,
        public readonly string $journalId,
        string $correlationId,
        ?string $actorId = null,
    ) {
        parent::__construct(
            aggregateId:   $commissionId,
            actorId:       $actorId,
            correlationId: $correlationId,
        );
    }

    public function eventName(): string { return 'Commission.Calculated'; }

    public function aggregateType(): string { return 'commission'; }

    public function payload(): array
    {
        return [
            'commission_id'    => $this->commissionId,
            'agent_id'         => $this->agentId,
            'transaction_id'   => $this->transactionId,
            'transaction_type' => $this->transactionType,
            'amount'           => $this->amount,
            'currency_code'    => $this->currencyCode,
            'rule_id'          => $this->ruleId,
            'journal_id'       => $this->journalId,
        ];
    }
}
