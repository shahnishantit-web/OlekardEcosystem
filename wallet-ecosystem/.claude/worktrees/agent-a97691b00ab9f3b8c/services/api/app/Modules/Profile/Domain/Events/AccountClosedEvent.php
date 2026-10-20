<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class AccountClosedEvent extends BaseIntegrationEvent
{
    public function __construct(string $userId, ?string $actorId, string $correlationId)
    {
        parent::__construct($userId, $actorId, $correlationId);
    }

    public function aggregateType(): string
    {
        return 'Profile.Account';
    }

    public function eventName(): string
    {
        return 'Profile.Account.Closed';
    }

    public function payload(): array
    {
        return [
            'user_id'  => $this->aggregateId(),
            'actor_id' => $this->actorId(),
        ];
    }
}
