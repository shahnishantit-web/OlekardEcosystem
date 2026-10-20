<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class ReportJobRequestedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $jobId,
        public string $reportType,
        public string $userId,
        public string $correlationId,
        public string $occurredAt,
    ) {}

    public function eventName(): string { return 'Reporting.ReportJob.Requested'; }
    public function occurredAt(): string { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'job_id'        => $this->jobId,
            'report_type'   => $this->reportType,
            'user_id'       => $this->userId,
            'correlation_id' => $this->correlationId,
            'occurred_at'   => $this->occurredAt,
        ];
    }
}
