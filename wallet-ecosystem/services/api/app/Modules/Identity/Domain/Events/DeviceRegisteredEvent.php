<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class DeviceRegisteredEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $deviceId,
        ?string $actorId,
        string $correlationId,
        private readonly string $userId,
        private readonly string $platform,
        private readonly string $deviceName,
        private readonly string $status,
        ?string $causationId = null,
    ) {
        parent::__construct($deviceId, $actorId, $correlationId, $causationId);
    }

    public function aggregateType(): string
    {
        return 'identity.device';
    }

    public function eventName(): string
    {
        return 'Device.Device.Registered';
    }

    public function payload(): array
    {
        return [
            'device_id'   => $this->aggregateId(),
            'user_id'     => $this->userId,
            'platform'    => $this->platform,
            'device_name' => $this->deviceName,
            'status'      => $this->status,
        ];
    }
}
