<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;
use Carbon\CarbonImmutable;

/**
 * Emitted when AML restrictions are lifted from a user account.
 * E23 listener AmlRestrictionChangedListener depends on this event.
 * TODO: Wire this event in E18 AccountHoldService when restrictions are lifted.
 */
final class AmlRestrictionLiftedEvent extends BaseIntegrationEvent
{
    private readonly CarbonImmutable $restrictionOccurredAt;

    public function __construct(
        string $holdId,
        ?string $actorId,
        string $correlationId,
        private readonly string $userId,
        private readonly array $liftedRestrictions,
        private readonly string $auditEventId,
        CarbonImmutable $occurredAt,
        ?string $causationId = null,
    ) {
        parent::__construct($holdId, $actorId, $correlationId, $causationId);
        $this->restrictionOccurredAt = $occurredAt;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getLiftedRestrictions(): array
    {
        return $this->liftedRestrictions;
    }

    public function getAuditEventId(): string
    {
        return $this->auditEventId;
    }

    public function getOccurredAtTimestamp(): CarbonImmutable
    {
        return $this->restrictionOccurredAt;
    }

    public function aggregateType(): string
    {
        return 'Risk.AccountHold';
    }

    public function eventName(): string
    {
        return 'Risk.AmlRestriction.Lifted';
    }

    public function payload(): array
    {
        return [
            'user_id' => $this->userId,
            'lifted_restrictions' => $this->liftedRestrictions,
            'audit_event_id' => $this->auditEventId,
            'occurred_at' => $this->restrictionOccurredAt->toIso8601String(),
        ];
    }
}
