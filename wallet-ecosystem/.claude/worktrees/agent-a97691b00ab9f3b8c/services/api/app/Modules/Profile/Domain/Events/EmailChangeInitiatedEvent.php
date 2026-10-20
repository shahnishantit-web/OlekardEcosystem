<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class EmailChangeInitiatedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string                         $userId,
        string                         $actorId,
        string                         $correlationId,
        private readonly string        $challengeId,
        private readonly string        $newEmailMasked,
    ) {
        parent::__construct($userId, $actorId, $correlationId);
    }

    public function aggregateType(): string
    {
        return 'Profile.User';
    }

    public function eventName(): string
    {
        return 'Profile.User.EmailChangeInitiated';
    }

    public function payload(): array
    {
        return [
            'user_id'          => $this->aggregateId(),
            'challenge_id'     => $this->challengeId,
            'new_email_masked' => $this->newEmailMasked,
        ];
    }
}
