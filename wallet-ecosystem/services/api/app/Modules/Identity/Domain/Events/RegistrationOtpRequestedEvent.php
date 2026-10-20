<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class RegistrationOtpRequestedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $userId,
        ?string $actorId,
        string $correlationId,
        private readonly string $challengeId,
        private readonly string $channelType,
        private readonly string $maskedDestination,
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
        return 'Identity.User.RegistrationOtpRequested';
    }

    public function payload(): array
    {
        return [
            'challenge_id'       => $this->challengeId,
            'channel_type'       => $this->channelType,
            'masked_destination' => $this->maskedDestination,
        ];
    }
}
