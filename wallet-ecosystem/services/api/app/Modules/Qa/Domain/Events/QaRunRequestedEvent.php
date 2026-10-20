<?php

declare(strict_types=1);

namespace App\Modules\Qa\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;
use App\Modules\Qa\Domain\Enums\QaCategory;

final readonly class QaRunRequestedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string     $runId,
        public QaCategory $category,
        public string     $actorId,
        public string     $correlationId,
        private string    $occurredAt,
    ) {}

    public function eventName(): string
    {
        return sprintf('US_QA_%s_REQUESTED', $this->category->storyNumber());
    }

    public function occurredAt(): string     { return $this->occurredAt; }
    public function correlationId(): string  { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'run_id'         => $this->runId,
            'category'       => $this->category->value,
            'actor_id'       => $this->actorId,
            'correlation_id' => $this->correlationId,
            'occurred_at'    => $this->occurredAt,
        ];
    }
}
