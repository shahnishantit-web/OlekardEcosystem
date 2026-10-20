<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Domain\Contracts\DeviceBlockCheckServiceInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;

final class DeviceBlockCheckService implements DeviceBlockCheckServiceInterface
{
    public function __construct(
        private readonly DeviceRepositoryInterface $devices,
    ) {}

    public function isBlocked(string $deviceId): bool
    {
        if ($deviceId === '') {
            return false;
        }

        $device = $this->devices->findById($deviceId);

        return $device !== null && $device->status === 'blocked';
    }
}
