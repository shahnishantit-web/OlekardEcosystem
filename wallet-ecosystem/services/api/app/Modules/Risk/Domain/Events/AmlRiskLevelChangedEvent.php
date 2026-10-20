<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

/**
 * Emitted when a user's AML risk level is re-scored or manually overridden.
 * E23 listener AmlRiskLevelChangedListener depends on this event.
 * TODO: Wire this event in E18 RiskEvaluatorService when risk level changes.
 */
final class AmlRiskLevelChangedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string  $alertId,
        ?string $actorId,
        string  $correlationId,
        private readonly string $userId,
        private readonly string $newRiskLevel,
        private readonly string $previousRiskLevel,
        private readonly string $auditEventId,
        ?string $causationId = null,
    ) {
        parent::__construct($alertId, $actorId, $correlationId, $causationId);
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getNewRiskLevel(): string
    {
        return $this->newRiskLevel;
    }

    public function getPreviousRiskLevel(): string
    {
        return $this->previousRiskLevel;
    }

    public function getAuditEventId(): string
    {
        return $this->auditEventId;
    }

    public function aggregateType(): string
    {
        return 'Risk.Alert';
    }

    public function eventName(): string
    {
        return 'Risk.AmlRiskLevel.Changed';
    }

    public function payload(): array
    {
        return [
            'user_id'            => $this->userId,
            'new_risk_level'     => $this->newRiskLevel,
            'previous_risk_level' => $this->previousRiskLevel,
            'audit_event_id'     => $this->auditEventId,
        ];
    }
}
