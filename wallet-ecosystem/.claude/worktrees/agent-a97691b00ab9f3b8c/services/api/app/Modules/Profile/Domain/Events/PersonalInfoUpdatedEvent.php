<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class PersonalInfoUpdatedEvent extends BaseIntegrationEvent
{
    /**
     * @param string[] $changedFields Field names that were updated (values excluded — PII prevention)
     */
    public function __construct(
        string $userId,
        string $actorId,
        string $correlationId,
        private readonly string $actorType,
        private readonly array $changedFields,
    ) {
        parent::__construct($userId, $actorId, $correlationId);
    }

    public function aggregateType(): string
    {
        return 'Profile.User';
    }

    public function eventName(): string
    {
        return 'Profile.User.PersonalInfoUpdated';
    }

    public function payload(): array
    {
        return [
            'user_id'        => $this->aggregateId(),
            'actor_type'     => $this->actorType,
            'changed_fields' => $this->changedFields, // field names only — no PII values
        ];
    }
}
