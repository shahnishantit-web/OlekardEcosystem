<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Infrastructure\Models\DeviceTrustHistory;

interface DeviceTrustHistoryRepositoryInterface
{
    public function append(array $attributes): DeviceTrustHistory;

    /**
     * @return DeviceTrustHistory[]
     */
    public function listForDevice(string $deviceId): array;
}
