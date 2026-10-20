<?php

declare(strict_types=1);

namespace App\Modules\Qa\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;
use App\Modules\Qa\Domain\Enums\QaCategory;

final readonly class QaRunFailedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string     $runId,
        public QaCategory $category,
        public int        $failedCount,
        public int        $totalCount,
        public string     $correlationId,
        private string    $occurredAt,
    ) {}

    public function eventName(): string
    {
        return sprintf('US_QA_%s_FAILED', $this->category->storyNumber());
    }

    public function occurredAt(): string     { return $this->occurredAt; }
    public function correlationId(): string  { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'run_id'         => $this->runId,
            'category'       => $this->category->value,
            'failed_count'   => $this->failedCount,
            'total_count'    => $this->totalCount,
            'correlation_id' => $this->correlationId,
            'occurred_at'    => $this->occurredAt,
        ];
    }
}
