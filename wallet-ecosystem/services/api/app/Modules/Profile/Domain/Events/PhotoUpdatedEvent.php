<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class PhotoUpdatedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $userId,
        string $actorId,
        string $correlationId,
        private readonly string $actorType,
    ) {
        parent::__construct($userId, $actorId, $correlationId);
    }

    public function aggregateType(): string
    {
        return 'Profile.User';
    }

    public function eventName(): string
    {
        return 'Profile.User.PhotoUpdated';
    }

    public function payload(): array
    {
        return [
            'user_id'    => $this->aggregateId(),
            'actor_type' => $this->actorType,
            // No photo key or URL in payload — constitution P6, spec security requirements
        ];
    }
}
