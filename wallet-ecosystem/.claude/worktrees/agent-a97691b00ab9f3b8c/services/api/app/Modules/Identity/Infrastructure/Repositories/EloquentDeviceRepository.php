<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Infrastructure\Models\Device;
final class EloquentDeviceRepository implements DeviceRepositoryInterface
{
    public function findById(string $id): ?Device
    {
        return Device::find($id);
    }

    public function findByUserAndFingerprint(string $userId, string $fingerprintHash): ?Device
    {
        return Device::where('user_id', $userId)
            ->where('device_fingerprint', $fingerprintHash)
            ->first();
    }

    public function create(array $attributes): Device
    {
        return Device::create($attributes);
    }

    public function updateStatus(string $id, string $status, array $extra = []): void
    {
        Device::where('id', $id)->update(array_merge(['status' => $status], $extra));
    }

    public function listForUser(string $userId): array
    {
        return Device::where('user_id', $userId)->orderByDesc('last_seen_at')->get()->all();
    }

    public function updateLastSeen(string $id): void
    {
        Device::where('id', $id)->update(['last_seen_at' => now()]);
    }
}
