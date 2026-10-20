<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class LoginFailedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $userId,
        ?string $actorId,
        string $correlationId,
        private readonly string $actorType,
        private readonly string $maskedIdentifier,
        private readonly string $reason,
        ?string $causationId = null,
    ) {
        parent::__construct($userId, $actorId, $correlationId, $causationId);
    }

    public function aggregateType(): string
    {
        return 'Identity.Session';
    }

    public function eventName(): string
    {
        return 'Identity.Session.LoginFailed';
    }

    public function payload(): array
    {
        return [
            'user_id'            => $this->aggregateId(),
            'actor_type'         => $this->actorType,
            'masked_identifier'  => $this->maskedIdentifier,
            'reason'             => $this->reason,
        ];
    }
}
