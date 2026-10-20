<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class FloatLimitsConfiguredEvent extends BaseIntegrationEvent
{
    public function __construct(
        public readonly string $agentId,
        public readonly array  $changes,
        string $correlationId,
        ?string $actorId = null,
    ) {
        parent::__construct(
            aggregateId:   $agentId,
            actorId:       $actorId,
            correlationId: $correlationId,
        );
    }

    public function eventName(): string { return 'AgentFloat.Limits.Configured'; }

    public function aggregateType(): string { return 'agent_float_limits'; }

    public function payload(): array
    {
        return [
            'agent_id' => $this->agentId,
            'changes'  => $this->changes,
        ];
    }
}
