<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class AdminPasswordResetSucceededEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $userId,
        string $correlationId,
    ) {
        parent::__construct($userId, null, $correlationId, null);
    }

    public function aggregateType(): string
    {
        return 'Identity.AdminAccount';
    }

    public function eventName(): string
    {
        return 'Identity.AdminAccount.PasswordResetSucceeded';
    }

    public function payload(): array
    {
        return [
            'user_id' => $this->aggregateId(),
        ];
    }
}
