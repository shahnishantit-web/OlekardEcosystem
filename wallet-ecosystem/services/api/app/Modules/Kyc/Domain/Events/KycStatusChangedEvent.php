<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

/**
 * Emitted when a user's KYC tier changes (case approval, reverification, expiry).
 * E23 listeners (KycStatusChangedListener) depend on this event.
 * TODO: Wire this event in E05 KycCaseService when KYC tier transitions occur.
 */
final class KycStatusChangedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $caseId,
        ?string $actorId,
        string $correlationId,
        private readonly string $userId,
        private readonly int    $newTier,
        private readonly int    $previousTier,
        private readonly string $auditEventId,
        ?string $causationId = null,
    ) {
        parent::__construct($caseId, $actorId, $correlationId, $causationId);
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getNewTier(): int
    {
        return $this->newTier;
    }

    public function getPreviousTier(): int
    {
        return $this->previousTier;
    }

    public function getAuditEventId(): string
    {
        return $this->auditEventId;
    }

    public function aggregateType(): string
    {
        return 'Kyc.Case';
    }

    public function eventName(): string
    {
        return 'Kyc.Status.Changed';
    }

    public function payload(): array
    {
        return [
            'user_id'       => $this->userId,
            'new_tier'      => $this->newTier,
            'previous_tier' => $this->previousTier,
            'audit_event_id' => $this->auditEventId,
        ];
    }
}
