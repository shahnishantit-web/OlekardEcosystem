<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class DeviceSuspiciousDetectedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $deviceId,
        ?string $actorId,
        string $correlationId,
        private readonly string $userId,
        private readonly array $riskSignals,
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
        return 'Device.Device.SuspiciousDetected';
    }

    public function payload(): array
    {
        return [
            'device_id'    => $this->aggregateId(),
            'user_id'      => $this->userId,
            'risk_signals' => $this->riskSignals,
        ];
    }
}
