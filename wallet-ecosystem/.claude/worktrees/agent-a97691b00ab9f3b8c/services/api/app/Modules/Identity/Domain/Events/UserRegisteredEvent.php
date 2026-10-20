<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class UserRegisteredEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $userId,
        ?string $actorId,
        string $correlationId,
        private readonly string $actorType,
        private readonly string $maskedIdentifier,
        private readonly string $status,
        ?string $causationId = null,
    ) {
        parent::__construct($userId, $actorId, $correlationId, $causationId);
    }

    public function aggregateType(): string
    {
        return 'Identity.User';
    }

    public function eventName(): string
    {
        return 'Identity.User.Registered';
    }

    public function payload(): array
    {
        return [
            'user_id'           => $this->aggregateId(),
            'actor_type'        => $this->actorType,
            'masked_identifier' => $this->maskedIdentifier,
            'status'            => $this->status,
        ];
    }
}
