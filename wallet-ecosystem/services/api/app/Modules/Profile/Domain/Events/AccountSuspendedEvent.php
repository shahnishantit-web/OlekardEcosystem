<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class AccountSuspendedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string                  $userId,
        string                  $actorId,
        string                  $correlationId,
        private readonly string $reason,
    ) {
        parent::__construct($userId, $actorId, $correlationId);
    }

    public function aggregateType(): string
    {
        return 'Profile.Account';
    }

    public function eventName(): string
    {
        return 'Profile.Account.Suspended';
    }

    public function payload(): array
    {
        return [
            'user_id' => $this->aggregateId(),
            'reason'  => $this->reason,
        ];
    }
}
