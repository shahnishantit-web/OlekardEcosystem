<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Listeners;

use App\Modules\Audit\Application\Services\ComplianceStatusService;
use App\Modules\Risk\Domain\Events\AmlRestrictionAppliedEvent;
use App\Modules\Risk\Domain\Events\AmlRestrictionLiftedEvent;

final class AmlRestrictionChangedListener
{
    public function __construct(
        private readonly ComplianceStatusService $service,
    ) {}

    public function handleApplied(AmlRestrictionAppliedEvent $event): void
    {
        $this->service->applyRestrictionChange(
            userId:        $event->getUserId(),
            restrictions:  $event->getRestrictions(),
            auditEventId:  $event->getAuditEventId(),
            correlationId: $event->correlationId(),
            occurredAt:    $event->getOccurredAtTimestamp(),
        );
    }

    public function handleLifted(AmlRestrictionLiftedEvent $event): void
    {
        // On lift, restrictions array becomes empty (or the remaining ones after removal)
        $this->service->applyRestrictionChange(
            userId:        $event->getUserId(),
            restrictions:  [],
            auditEventId:  $event->getAuditEventId(),
            correlationId: $event->correlationId(),
            occurredAt:    $event->getOccurredAtTimestamp(),
        );
    }
}
