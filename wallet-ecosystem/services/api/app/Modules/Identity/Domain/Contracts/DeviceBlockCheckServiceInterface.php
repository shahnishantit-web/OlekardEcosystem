<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

interface DeviceBlockCheckServiceInterface
{
    public function isBlocked(string $deviceId): bool;
}
