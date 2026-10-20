<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class ReportExportGeneratedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $exportId,
        public string $jobId,
        public string $format,
        public string $userId,
        public string $correlationId,
        public string $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'Reporting.ReportExport.Generated';
    }

    public function occurredAt(): string
    {
        return $this->occurredAt;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function toArray(): array
    {
        return [
            'export_id'      => $this->exportId,
            'job_id'         => $this->jobId,
            'format'         => $this->format,
            'user_id'        => $this->userId,
            'correlation_id' => $this->correlationId,
            'occurred_at'    => $this->occurredAt,
        ];
    }
}
