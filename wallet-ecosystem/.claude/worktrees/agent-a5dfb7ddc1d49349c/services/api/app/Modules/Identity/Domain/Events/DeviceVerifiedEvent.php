<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

/**
 * T100 — emitted when a newly detected device is verified via OTP and promoted to trusted.
 * Spec event: Identity.Device.Verified
 */
final class DeviceVerifiedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $userId,
        ?string $actorId,
        string $correlationId,
        private readonly string $deviceId,
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
        return 'Identity.Device.Verified';
    }

    public function payload(): array
    {
        return [
            'user_id'   => $this->aggregateId(),
            'device_id' => $this->deviceId,
        ];
    }
}
