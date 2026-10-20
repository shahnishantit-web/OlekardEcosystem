<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class DisputeInvestigationStartedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $disputeId,
        public string $investigatorId,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'Support.Dispute.InvestigationStarted'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'dispute_id'      => $this->disputeId,
            'investigator_id' => $this->investigatorId,
            'occurred_at'     => $this->occurredAt,
            'correlation_id'  => $this->correlationId,
        ];
    }
}
