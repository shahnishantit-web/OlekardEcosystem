<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class ReportJobCompletedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string  $jobId,
        public string  $reportType,
        public int     $rowCount,
        public string  $asOfAt,
        public string  $correlationId,
        public string  $occurredAt,
    ) {}

    public function eventName(): string { return 'Reporting.ReportJob.Completed'; }
    public function occurredAt(): string { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'job_id'         => $this->jobId,
            'report_type'    => $this->reportType,
            'row_count'      => $this->rowCount,
            'as_of_at'       => $this->asOfAt,
            'correlation_id' => $this->correlationId,
            'occurred_at'    => $this->occurredAt,
        ];
    }
}
