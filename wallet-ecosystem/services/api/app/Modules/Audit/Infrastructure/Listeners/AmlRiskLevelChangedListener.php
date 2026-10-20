<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Listeners;

use App\Modules\Audit\Application\Services\ComplianceStatusService;
use App\Modules\Risk\Domain\Events\AmlRiskLevelChangedEvent;
use Carbon\CarbonImmutable;

final class AmlRiskLevelChangedListener
{
    public function __construct(
        private readonly ComplianceStatusService $service,
    ) {}

    public function handle(AmlRiskLevelChangedEvent $event): void
    {
        $occurredAt = CarbonImmutable::parse($event->occurredAt());

        $this->service->applyAmlChange(
            userId:        $event->getUserId(),
            newRiskLevel:  $event->getNewRiskLevel(),
            auditEventId:  $event->getAuditEventId(),
            correlationId: $event->correlationId(),
            occurredAt:    $occurredAt,
        );
    }
}
