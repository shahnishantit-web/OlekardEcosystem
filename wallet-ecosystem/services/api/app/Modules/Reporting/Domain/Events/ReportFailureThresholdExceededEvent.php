<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class ReportFailureThresholdExceededEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $reportType,
        public int    $failureCount,
        public int    $windowMinutes,
        public int    $threshold,
        public string $correlationId,
        public string $occurredAt,
    ) {}

    public function eventName(): string { return 'Reporting.FailureThreshold.Exceeded'; }
    public function occurredAt(): string { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'report_type'    => $this->reportType,
            'failure_count'  => $this->failureCount,
            'window_minutes' => $this->windowMinutes,
            'threshold'      => $this->threshold,
            'correlation_id' => $this->correlationId,
            'occurred_at'    => $this->occurredAt,
        ];
    }
}
