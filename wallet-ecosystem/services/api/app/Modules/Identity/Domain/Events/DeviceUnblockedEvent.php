<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class DeviceUnblockedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $deviceId,
        ?string $actorId,
        string $correlationId,
        private readonly string $userId,
        private readonly string $adminActorId,
        private readonly string $reason,
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
        return 'Device.Device.Unblocked';
    }

    public function payload(): array
    {
        return [
            'device_id'      => $this->aggregateId(),
            'user_id'        => $this->userId,
            'admin_actor_id' => $this->adminActorId,
            'reason'         => $this->reason,
        ];
    }
}
