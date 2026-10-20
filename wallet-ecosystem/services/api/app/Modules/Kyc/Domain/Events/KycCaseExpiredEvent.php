<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class KycCaseExpiredEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $caseId,
        ?string $actorId,
        string $correlationId,
        private readonly string $userId,
        private readonly string $expiredAt,
        private readonly ?string $wasExpiresAt,
        ?string $causationId = null,
    ) {
        parent::__construct($caseId, $actorId, $correlationId, $causationId);
    }

    public function aggregateType(): string
    {
        return 'Kyc.Case';
    }

    public function eventName(): string
    {
        return 'Kyc.Case.Expired';
    }

    public function payload(): array
    {
        return [
            'case_id'       => $this->aggregateId(),
            'user_id'       => $this->userId,
            'expired_at'    => $this->expiredAt,
            'was_expires_at' => $this->wasExpiresAt,
        ];
    }
}
