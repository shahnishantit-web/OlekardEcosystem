<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class ContactSecurityAlertEvent extends BaseIntegrationEvent
{
    public function __construct(
        string                         $userId,
        string                         $actorId,
        string                         $correlationId,
        private readonly string        $contactType,
        private readonly string        $oldContactMasked,
    ) {
        parent::__construct($userId, $actorId, $correlationId);
    }

    public function aggregateType(): string
    {
        return 'Profile.User';
    }

    public function eventName(): string
    {
        return 'Profile.User.ContactSecurityAlertSent';
    }

    public function payload(): array
    {
        return [
            'user_id'           => $this->aggregateId(),
            'contact_type'      => $this->contactType,
            'old_contact_masked' => $this->oldContactMasked,
        ];
    }
}
