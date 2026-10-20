<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Handlers;

use App\Modules\Identity\Application\Commands\GetDeviceCommand;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;

final class GetDeviceHandler
{
    public function __construct(
        private readonly DeviceRepositoryInterface $devices,
    ) {}

    public function handle(GetDeviceCommand $command): array
    {
        $device = $this->devices->findById($command->deviceId);

        if ($device === null || (string) $device->user_id !== $command->userId) {
            throw new \RuntimeException('DEVICE_FORBIDDEN');
        }

        // Return safe fields only — device_fingerprint (SHA-256 hash) is never exposed.
        return [
            'id'           => $device->id,
            'device_name'  => $device->device_name,
            'platform'     => $device->platform,
            'status'       => $device->status,
            'trusted_at'   => $device->trusted_at,
            'last_seen_at' => $device->last_seen_at,
            'created_at'   => $device->created_at,
        ];
    }
}
