<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class ProfileCreatedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $userId,
        string $correlationId,
        private readonly string $actorType,
    ) {
        parent::__construct($userId, $userId, $correlationId);
    }

    public function aggregateType(): string
    {
        return 'Profile.User';
    }

    public function eventName(): string
    {
        return 'Profile.User.ProfileCreated';
    }

    public function payload(): array
    {
        return [
            'user_id'    => $this->aggregateId(),
            'actor_type' => $this->actorType,
        ];
    }
}
