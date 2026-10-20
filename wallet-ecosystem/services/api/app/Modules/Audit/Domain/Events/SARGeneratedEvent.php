<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class SARGeneratedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $sarId,
        public string $reportNumber,
        public string $subjectUserId,
        public string $createdByActorId,
        public string $occurredAtIso,
        public string $correlationId,
    ) {}

    public function eventName(): string
    {
        return 'audit.sar.generated.v1';
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
        return $this->sarId;
    }

    public function aggregateType(): string
    {
        return 'sar';
    }

    public function toArray(): array
    {
        return [
            'sar_id'               => $this->sarId,
            'report_number'        => $this->reportNumber,
            'subject_user_id'      => $this->subjectUserId,
            'created_by_actor_id'  => $this->createdByActorId,
            'occurred_at'          => $this->occurredAtIso,
            'correlation_id'       => $this->correlationId,
        ];
    }
}
