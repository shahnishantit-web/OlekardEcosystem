<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class AccountClosureBlockedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string                 $userId,
        string                 $actorId,
        string                 $correlationId,
        private readonly array $blockedBy,
    ) {
        parent::__construct($userId, $actorId, $correlationId);
    }

    public function aggregateType(): string
    {
        return 'Profile.Account';
    }

    public function eventName(): string
    {
        return 'Profile.Account.ClosureBlocked';
    }

    public function payload(): array
    {
        return [
            'user_id'    => $this->aggregateId(),
            'blocked_by' => $this->blockedBy,
        ];
    }
}
