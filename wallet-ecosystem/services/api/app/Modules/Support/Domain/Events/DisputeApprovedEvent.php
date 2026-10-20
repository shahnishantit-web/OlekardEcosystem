<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class DisputeApprovedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $disputeId,
        public string $approvedById,
        public string $resolutionNote,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'Support.Dispute.Approved'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'dispute_id'      => $this->disputeId,
            'approved_by_id'  => $this->approvedById,
            'resolution_note' => $this->resolutionNote,
            'occurred_at'     => $this->occurredAt,
            'correlation_id'  => $this->correlationId,
        ];
    }
}
