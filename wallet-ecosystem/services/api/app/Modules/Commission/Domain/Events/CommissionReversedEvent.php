<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class CommissionReversedEvent extends BaseIntegrationEvent
{
    public function __construct(
        public readonly string $commissionId,
        public readonly string $agentId,
        public readonly string $reversalJournalId,
        public readonly int    $amount,
        public readonly string $currencyCode,
        public readonly string $originalStatus,
        string $correlationId,
        ?string $actorId = null,
    ) {
        parent::__construct(
            aggregateId:   $commissionId,
            actorId:       $actorId,
            correlationId: $correlationId,
        );
    }

    public function eventName(): string { return 'Commission.Reversed'; }

    public function aggregateType(): string { return 'commission'; }

    public function payload(): array
    {
        return [
            'commission_id'      => $this->commissionId,
            'agent_id'           => $this->agentId,
            'reversal_journal_id'=> $this->reversalJournalId,
            'amount'             => $this->amount,
            'currency_code'      => $this->currencyCode,
            'original_status'    => $this->originalStatus,
        ];
    }
}
