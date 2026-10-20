<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class AgentSuspendedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $agentId,
        public string $suspendedBy,
        public string $reason,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'agent.suspended'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'agent_id'       => $this->agentId,
            'suspended_by'   => $this->suspendedBy,
            'reason'         => $this->reason,
            'occurred_at'    => $this->occurredAt,
            'correlation_id' => $this->correlationId,
        ];
    }
}
