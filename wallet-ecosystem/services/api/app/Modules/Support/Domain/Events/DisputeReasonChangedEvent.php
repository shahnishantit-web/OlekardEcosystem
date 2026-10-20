<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class DisputeReasonChangedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string  $disputeId,
        public string  $reason,
        public ?string $description,
        public string  $actorId,
        public string  $occurredAt,
        public string  $correlationId,
    ) {}

    public function eventName(): string     { return 'Support.Dispute.ReasonChanged'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'dispute_id'    => $this->disputeId,
            'reason'        => $this->reason,
            'description'   => $this->description,
            'actor_id'      => $this->actorId,
            'occurred_at'   => $this->occurredAt,
            'correlation_id' => $this->correlationId,
        ];
    }
}
