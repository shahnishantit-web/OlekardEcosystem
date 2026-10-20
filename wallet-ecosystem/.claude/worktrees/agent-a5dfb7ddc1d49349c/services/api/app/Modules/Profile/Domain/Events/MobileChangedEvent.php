<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class MobileChangedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string                         $userId,
        string                         $actorId,
        string                         $correlationId,
        private readonly string        $newMobileMasked,
    ) {
        parent::__construct($userId, $actorId, $correlationId);
    }

    public function aggregateType(): string
    {
        return 'Profile.User';
    }

    public function eventName(): string
    {
        return 'Profile.User.MobileChanged';
    }

    public function payload(): array
    {
        return [
            'user_id'           => $this->aggregateId(),
            'new_mobile_masked' => $this->newMobileMasked,
        ];
    }
}
