<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class NewDeviceDetectedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $userId,
        ?string $actorId,
        string $correlationId,
        private readonly string $deviceId,
        private readonly string $maskedIdentifier,
        ?string $causationId = null,
    ) {
        parent::__construct($userId, $actorId, $correlationId, $causationId);
    }

    public function aggregateType(): string
    {
        return 'Identity.Device';
    }

    public function eventName(): string
    {
        return 'Identity.Device.NewDeviceDetected';
    }

    public function payload(): array
    {
        return [
            'user_id'           => $this->aggregateId(),
            'device_id'         => $this->deviceId,
            'masked_identifier' => $this->maskedIdentifier,
        ];
    }
}
