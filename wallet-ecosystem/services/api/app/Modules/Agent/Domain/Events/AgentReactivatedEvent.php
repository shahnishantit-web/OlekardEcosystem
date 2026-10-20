<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class AgentReactivatedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $agentId,
        public string $reactivatedBy,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'agent.reactivated'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'agent_id'        => $this->agentId,
            'reactivated_by'  => $this->reactivatedBy,
            'occurred_at'     => $this->occurredAt,
            'correlation_id'  => $this->correlationId,
        ];
    }
}
