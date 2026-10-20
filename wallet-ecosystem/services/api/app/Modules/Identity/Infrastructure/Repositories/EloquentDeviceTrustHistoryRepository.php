<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Contracts\DeviceTrustHistoryRepositoryInterface;
use App\Modules\Identity\Infrastructure\Models\DeviceTrustHistory;
use Ramsey\Uuid\Uuid;

final class EloquentDeviceTrustHistoryRepository implements DeviceTrustHistoryRepositoryInterface
{
    /**
     * Append a trust history record. MUST be called inside an active DB transaction.
     */
    public function append(array $attributes): DeviceTrustHistory
    {
        if (! isset($attributes['id'])) {
            $attributes['id'] = (string) Uuid::uuid7();
        }

        if (! isset($attributes['created_at'])) {
            $attributes['created_at'] = now();
        }

        return DeviceTrustHistory::create($attributes);
    }

    /**
     * @return DeviceTrustHistory[]
     */
    public function listForDevice(string $deviceId): array
    {
        return DeviceTrustHistory::where('device_id', $deviceId)
            ->orderBy('created_at', 'asc')
            ->get()
            ->all();
    }
}
