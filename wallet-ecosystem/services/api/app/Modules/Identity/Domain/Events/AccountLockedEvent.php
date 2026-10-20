<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class AccountLockedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $userId,
        ?string $actorId,
        string $correlationId,
        private readonly string $maskedIdentifier,
        private readonly int $lockoutEpisodeCount,
        private readonly ?string $lockedUntil,
        ?string $causationId = null,
    ) {
        parent::__construct($userId, $actorId, $correlationId, $causationId);
    }

    public function aggregateType(): string
    {
        return 'Identity.Account';
    }

    public function eventName(): string
    {
        return 'Identity.Account.Locked';
    }

    public function payload(): array
    {
        return [
            'user_id'               => $this->aggregateId(),
            'masked_identifier'     => $this->maskedIdentifier,
            'lockout_episode_count' => $this->lockoutEpisodeCount,
            'locked_until'          => $this->lockedUntil,
        ];
    }
}
