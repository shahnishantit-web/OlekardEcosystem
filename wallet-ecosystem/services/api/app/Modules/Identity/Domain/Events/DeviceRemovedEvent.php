<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class DeviceRemovedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $deviceId,
        ?string $actorId,
        string $correlationId,
        private readonly string $userId,
        private readonly int $sessionsRevoked,
        private readonly bool $removedByAdmin,
        private readonly ?string $adminActorId,
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
        return 'Device.Device.Removed';
    }

    public function payload(): array
    {
        return [
            'device_id'        => $this->aggregateId(),
            'user_id'          => $this->userId,
            'sessions_revoked' => $this->sessionsRevoked,
            'removed_by_admin' => $this->removedByAdmin,
            'admin_actor_id'   => $this->adminActorId,
        ];
    }
}
