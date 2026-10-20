<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class ReportScheduleTriggeredEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $scheduleId,
        public string $jobId,
        public string $triggeredAt,
        public string $correlationId,
        public string $occurredAt,
    ) {}

    public function eventName(): string { return 'Reporting.ReportSchedule.Triggered'; }
    public function occurredAt(): string { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'schedule_id'    => $this->scheduleId,
            'job_id'         => $this->jobId,
            'triggered_at'   => $this->triggeredAt,
            'correlation_id' => $this->correlationId,
            'occurred_at'    => $this->occurredAt,
        ];
    }
}
