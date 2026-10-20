<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

/**
 * Emitted by the session cleanup job when a session auto-expires via TTL.
 * Cleanup job (scheduled task) is not yet implemented; class is ready for E01 Phase 16+.
 */
final class SessionExpiredEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $sessionId,
        ?string $actorId,
        string $correlationId,
        private readonly string $userId,
        private readonly string $actorType,
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
        return 'Identity.Session.Expired';
    }

    public function payload(): array
    {
        return [
            'session_id' => $this->aggregateId(),
            'user_id'    => $this->userId,
            'actor_type' => $this->actorType,
        ];
    }
}
