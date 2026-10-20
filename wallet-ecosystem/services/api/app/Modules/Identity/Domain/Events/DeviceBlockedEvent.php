<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class DeviceBlockedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $deviceId,
        ?string $actorId,
        string $correlationId,
        private readonly string $userId,
        private readonly ?string $reason,
        private readonly int $sessionsRevoked,
        private readonly bool $blockedByAdmin,
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
        return 'Device.Device.Blocked';
    }

    public function payload(): array
    {
        return [
            'device_id'        => $this->aggregateId(),
            'user_id'          => $this->userId,
            'reason'           => $this->reason,
            'sessions_revoked' => $this->sessionsRevoked,
            'blocked_by_admin' => $this->blockedByAdmin,
            'admin_actor_id'   => $this->adminActorId,
        ];
    }
}
