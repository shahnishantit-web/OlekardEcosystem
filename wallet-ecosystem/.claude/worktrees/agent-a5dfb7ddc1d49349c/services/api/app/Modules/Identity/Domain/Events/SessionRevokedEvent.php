<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class SessionRevokedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $sessionId,
        ?string $actorId,
        string $correlationId,
        private readonly string $targetUserId,
        private readonly string $targetActorType,
        private readonly string $reason,
        ?string $causationId = null,
    ) {
        parent::__construct($sessionId, $actorId, $correlationId, $causationId);
    }

    public function aggregateType(): string
    {
        return 'Identity.Session';
    }

    public function eventName(): string
    {
        return 'Identity.Session.Revoked';
    }

    public function payload(): array
    {
        return [
            'session_id'       => $this->aggregateId(),
            'target_user_id'   => $this->targetUserId,
            'target_actor_type' => $this->targetActorType,
            'reason'           => $this->reason,
        ];
    }
}
