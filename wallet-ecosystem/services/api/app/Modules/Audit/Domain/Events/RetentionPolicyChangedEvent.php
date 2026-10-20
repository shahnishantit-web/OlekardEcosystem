<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;
use Carbon\CarbonImmutable;
use Ramsey\Uuid\Uuid;

final readonly class RetentionPolicyChangedEvent implements IntegrationEventInterface
{
    public string $eventId;
    private string $occurredAtValue;

    public function __construct(
        public string $policyId,
        public string $dataCategory,
        public string $action,
        public string $configuredByActorId,
        public string $correlationId,
        ?string $occurredAt = null,
    ) {
        $this->eventId          = (string) Uuid::uuid7();
        $this->occurredAtValue  = $occurredAt ?? CarbonImmutable::now()->toIso8601String();
    }

    public function eventName(): string
    {
        return 'audit.retention_policy.changed.v1';
    }

    public function occurredAt(): string
    {
        return $this->occurredAtValue;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function toArray(): array
    {
        return [
            'event_id'              => $this->eventId,
            'event_name'            => $this->eventName(),
            'policy_id'             => $this->policyId,
            'data_category'         => $this->dataCategory,
            'action'                => $this->action,
            'configured_by_actor_id'=> $this->configuredByActorId,
            'correlation_id'        => $this->correlationId,
            'occurred_at'           => $this->occurredAtValue,
        ];
    }
}
