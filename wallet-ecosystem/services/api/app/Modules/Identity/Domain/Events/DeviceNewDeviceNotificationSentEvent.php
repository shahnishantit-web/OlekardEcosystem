<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class DeviceNewDeviceNotificationSentEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $deviceId,
        ?string $actorId,
        string $correlationId,
        private readonly string $userId,
        private readonly string $acknowledgmentExpiresAt,
        private readonly string $deviceName,
        private readonly string $platform,
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
        return 'Device.Device.NewDeviceNotificationSent';
    }

    public function payload(): array
    {
        return [
            'device_id'                  => $this->aggregateId(),
            'user_id'                    => $this->userId,
            'platform'                   => $this->platform,
            'device_name'                => $this->deviceName,
            'acknowledgment_expires_at'  => $this->acknowledgmentExpiresAt,
        ];
    }
}
