<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class KycCaseReverificationTriggeredEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $caseId,
        ?string $actorId,
        string $correlationId,
        private readonly string $userId,
        private readonly string $triggeredBy,
        private readonly int $attemptNumber,
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
        return 'Kyc.Case.ReverificationTriggered';
    }

    public function payload(): array
    {
        return [
            'case_id'        => $this->aggregateId(),
            'user_id'        => $this->userId,
            'triggered_by'   => $this->triggeredBy,
            'attempt_number' => $this->attemptNumber,
        ];
    }
}
