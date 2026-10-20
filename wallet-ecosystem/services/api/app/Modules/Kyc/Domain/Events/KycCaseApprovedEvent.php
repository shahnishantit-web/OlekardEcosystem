<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class KycCaseApprovedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $caseId,
        ?string $actorId,
        string $correlationId,
        private readonly string $userId,
        private readonly ?string $verifiedAt,
        private readonly ?string $expiresAt,
        private readonly string $verificationMethod,
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
        return 'Kyc.Case.Approved';
    }

    public function payload(): array
    {
        return [
            'case_id'             => $this->aggregateId(),
            'user_id'             => $this->userId,
            'verified_at'         => $this->verifiedAt,
            'expires_at'          => $this->expiresAt,
            'verification_method' => $this->verificationMethod,
            'attempt_number'      => $this->attemptNumber,
        ];
    }
}
