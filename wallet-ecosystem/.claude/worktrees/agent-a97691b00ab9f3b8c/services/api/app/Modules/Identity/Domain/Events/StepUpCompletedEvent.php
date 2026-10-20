<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class StepUpCompletedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $sessionId,
        ?string $actorId,
        string $correlationId,
        private readonly string $userId,
        private readonly string $actorType,
        private readonly string $scope,
        ?string $causationId = null,
    ) {
        parent::__construct($sessionId, $actorId, $correlationId, $causationId);
    }

    public function aggregateType(): string
    {
        return 'Identity.MFA';
    }

    public function eventName(): string
    {
        return 'Identity.MFA.StepUpCompleted';
    }

    public function payload(): array
    {
        return [
            'session_id' => $this->aggregateId(),
            'user_id'    => $this->userId,
            'actor_type' => $this->actorType,
            'scope'      => $this->scope,
        ];
    }
}
