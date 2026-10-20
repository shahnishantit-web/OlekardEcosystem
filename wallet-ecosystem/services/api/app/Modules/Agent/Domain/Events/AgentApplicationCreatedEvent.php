<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class AgentApplicationCreatedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $applicationId,
        public string $userId,
        public string $agentType,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'agent.application.created'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'application_id' => $this->applicationId,
            'user_id'        => $this->userId,
            'agent_type'     => $this->agentType,
            'occurred_at'    => $this->occurredAt,
            'correlation_id' => $this->correlationId,
        ];
    }
}
