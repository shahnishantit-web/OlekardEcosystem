<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class CommissionPayoutFailedEvent extends BaseIntegrationEvent
{
    public function __construct(
        public readonly string $payoutId,
        public readonly string $agentId,
        public readonly string $errorMessage,
        string $correlationId,
        ?string $actorId = null,
    ) {
        parent::__construct(
            aggregateId:   $payoutId,
            actorId:       $actorId,
            correlationId: $correlationId,
        );
    }

    public function eventName(): string { return 'Commission.Payout.Failed'; }

    public function aggregateType(): string { return 'commission_payout'; }

    public function payload(): array
    {
        return [
            'payout_id'     => $this->payoutId,
            'agent_id'      => $this->agentId,
            'error_message' => $this->errorMessage,
        ];
    }
}
