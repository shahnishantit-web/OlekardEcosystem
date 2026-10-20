<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class AgentApplicationKycBypassedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $applicationId,
        public string $adminActorId,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'agent.application.kyc_bypassed'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'application_id' => $this->applicationId,
            'admin_actor_id' => $this->adminActorId,
            'bypass_reason'  => 'ADMIN_KYC_BYPASS',
            'occurred_at'    => $this->occurredAt,
            'correlation_id' => $this->correlationId,
        ];
    }
}
