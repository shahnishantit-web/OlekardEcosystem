<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class ComplianceReportGeneratedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $reportId,
        public string $reportType,
        public string $requestedByActorId,
        public string $occurredAtIso,
        public string $correlationId,
    ) {}

    public function eventName(): string
    {
        return 'audit.compliance_report.generated.v1';
    }

    public function occurredAt(): string
    {
        return $this->occurredAtIso;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function toArray(): array
    {
        return [
            'report_id'              => $this->reportId,
            'report_type'            => $this->reportType,
            'requested_by_actor_id'  => $this->requestedByActorId,
            'occurred_at'            => $this->occurredAtIso,
            'correlation_id'         => $this->correlationId,
        ];
    }
}
