<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class AgentCustomerSearchedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $agentId,
        public string $actorId,
        public string $query,
        public int    $resultCount,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'agent.customer.searched'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'agent_id'       => $this->agentId,
            'actor_id'       => $this->actorId,
            'query'          => $this->query,
            'result_count'   => $this->resultCount,
            'occurred_at'    => $this->occurredAt,
            'correlation_id' => $this->correlationId,
        ];
    }
}
