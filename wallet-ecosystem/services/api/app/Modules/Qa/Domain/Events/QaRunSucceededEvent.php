<?php

declare(strict_types=1);

namespace App\Modules\Qa\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;
use App\Modules\Qa\Domain\Enums\QaCategory;

final readonly class QaRunSucceededEvent implements IntegrationEventInterface
{
    public function __construct(
        public string     $runId,
        public QaCategory $category,
        public int        $passedCount,
        public int        $totalCount,
        public string     $correlationId,
        private string    $occurredAt,
    ) {}

    public function eventName(): string
    {
        return sprintf('US_QA_%s_SUCCEEDED', $this->category->storyNumber());
    }

    public function occurredAt(): string     { return $this->occurredAt; }
    public function correlationId(): string  { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'run_id'         => $this->runId,
            'category'       => $this->category->value,
            'passed_count'   => $this->passedCount,
            'total_count'    => $this->totalCount,
            'correlation_id' => $this->correlationId,
            'occurred_at'    => $this->occurredAt,
        ];
    }
}
