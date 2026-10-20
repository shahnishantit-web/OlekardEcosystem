<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class DeviceTrustPromotedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $deviceId,
        ?string $actorId,
        string $correlationId,
        private readonly string $userId,
        private readonly string $fromStatus,
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
        return 'Device.Trust.Promoted';
    }

    public function payload(): array
    {
        return [
            'device_id'   => $this->aggregateId(),
            'user_id'     => $this->userId,
            'from_status' => $this->fromStatus,
            'to_status'   => 'trusted',
        ];
    }
}
