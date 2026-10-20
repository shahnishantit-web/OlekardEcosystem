<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class AuditEventRecordedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $auditEventId,
        public string $eventCategory,
        public string $eventType,
        public ?string $actorId,
        public string $occurredAtIso,
        public string $correlationId,
    ) {}

    public function eventName(): string
    {
        return 'audit.event_recorded.v1';
    }

    public function occurredAt(): string
    {
        return $this->occurredAtIso;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function aggregateId(): string
    {
        return $this->auditEventId;
    }

    public function aggregateType(): string
    {
        return 'audit_event';
    }

    public function toArray(): array
    {
        return [
            'audit_event_id' => $this->auditEventId,
            'event_category' => $this->eventCategory,
            'event_type'     => $this->eventType,
            'actor_id'       => $this->actorId,
            'occurred_at'    => $this->occurredAtIso,
            'correlation_id' => $this->correlationId,
        ];
    }
}
