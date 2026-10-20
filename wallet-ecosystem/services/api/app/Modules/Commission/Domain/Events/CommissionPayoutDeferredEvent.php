<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class CommissionPayoutDeferredEvent extends BaseIntegrationEvent
{
    public function __construct(
        public readonly string $payoutId,
        public readonly string $agentId,
        public readonly int    $totalAmount,
        public readonly string $currencyCode,
        public readonly string $reason,
        string $correlationId,
        ?string $actorId = null,
    ) {
        parent::__construct(
            aggregateId:   $payoutId,
            actorId:       $actorId,
            correlationId: $correlationId,
        );
    }

    public function eventName(): string { return 'Commission.Payout.Deferred'; }

    public function aggregateType(): string { return 'commission_payout'; }

    public function payload(): array
    {
        return [
            'payout_id'     => $this->payoutId,
            'agent_id'      => $this->agentId,
            'total_amount'  => $this->totalAmount,
            'currency_code' => $this->currencyCode,
            'reason'        => $this->reason,
        ];
    }
}
