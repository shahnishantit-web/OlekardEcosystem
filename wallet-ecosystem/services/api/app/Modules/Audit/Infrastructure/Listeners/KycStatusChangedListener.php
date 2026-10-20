<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Listeners;

use App\Modules\Audit\Application\Services\ComplianceStatusService;
use App\Modules\Kyc\Domain\Events\KycStatusChangedEvent;
use Carbon\CarbonImmutable;

final class KycStatusChangedListener
{
    public function __construct(
        private readonly ComplianceStatusService $service,
    ) {}

    public function handle(KycStatusChangedEvent $event): void
    {
        $occurredAt = CarbonImmutable::parse($event->occurredAt());

        $this->service->applyKycChange(
            userId:        $event->getUserId(),
            newTier:       $event->getNewTier(),
            auditEventId:  $event->getAuditEventId(),
            correlationId: $event->correlationId(),
            occurredAt:    $occurredAt,
        );
    }
}
