<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;
use Carbon\CarbonImmutable;

/**
 * Emitted when AML restrictions are applied to a user account.
 * E23 listener AmlRestrictionChangedListener depends on this event.
 * TODO: Wire this event in E18 AccountHoldService when restrictions are applied.
 */
final class AmlRestrictionAppliedEvent extends BaseIntegrationEvent
{
    private readonly CarbonImmutable $restrictionOccurredAt;

    public function __construct(
        string $holdId,
        ?string $actorId,
        string $correlationId,
        private readonly string $userId,
        private readonly array $restrictions,
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

    public function getRestrictions(): array
    {
        return $this->restrictions;
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
        return 'Risk.AmlRestriction.Applied';
    }

    public function payload(): array
    {
        return [
            'user_id' => $this->userId,
            'restrictions' => $this->restrictions,
            'audit_event_id' => $this->auditEventId,
            'occurred_at' => $this->restrictionOccurredAt->toIso8601String(),
        ];
    }
}
