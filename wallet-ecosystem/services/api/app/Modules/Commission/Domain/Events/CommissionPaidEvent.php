<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class CommissionPaidEvent extends BaseIntegrationEvent
{
    public function __construct(
        public readonly string $commissionId,
        public readonly string $agentId,
        public readonly string $payoutId,
        public readonly int    $amount,
        public readonly string $currencyCode,
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

    public function eventName(): string { return 'Commission.Paid'; }

    public function aggregateType(): string { return 'commission'; }

    public function payload(): array
    {
        return [
            'commission_id'  => $this->commissionId,
            'agent_id'       => $this->agentId,
            'payout_id'      => $this->payoutId,
            'amount'         => $this->amount,
            'currency_code'  => $this->currencyCode,
            'journal_id'     => $this->journalId,
        ];
    }
}
