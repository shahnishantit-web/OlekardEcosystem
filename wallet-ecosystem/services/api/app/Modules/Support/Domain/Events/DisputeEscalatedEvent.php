<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class DisputeEscalatedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string  $disputeId,
        public string  $escalatedById,
        public ?string $reason,
        public string  $occurredAt,
        public string  $correlationId,
    ) {}

    public function eventName(): string     { return 'Support.Dispute.Escalated'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'dispute_id'     => $this->disputeId,
            'escalated_by_id' => $this->escalatedById,
            'reason'         => $this->reason,
            'occurred_at'    => $this->occurredAt,
            'correlation_id' => $this->correlationId,
        ];
    }
}
