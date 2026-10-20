<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class AgentRejectedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $applicationId,
        public string $rejectedBy,
        public string $reason,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'agent.rejected'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'application_id' => $this->applicationId,
            'rejected_by'    => $this->rejectedBy,
            'reason'         => $this->reason,
            'occurred_at'    => $this->occurredAt,
            'correlation_id' => $this->correlationId,
        ];
    }
}
