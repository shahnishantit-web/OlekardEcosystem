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

    /**
     * @return Device[]
     */
    public function findByFingerprint(string $fingerprintHash): array
    {
        return Device::where('device_fingerprint', $fingerprintHash)
            ->get()
            ->all();
    }

    public function countForUser(string $userId): int
    {
        return Device::where('user_id', $userId)
            ->whereNotIn('status', ['revoked'])
            ->count();
    }

    /**
     * @return array{data: Device[], meta: array{total: int, page: int, per_page: int, last_page: int}}
     */
    public function listForUserPaginated(string $userId, int $page, int $perPage, ?string $status = null, ?string $platform = null): array
    {
        $query = Device::where('user_id', $userId);

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($platform !== null) {
            $query->where('platform', $platform);
        }

        $total    = $query->count();
        $lastPage = (int) ceil($total / $perPage);
        $data     = $query->orderByDesc('last_seen_at')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->all();

        return [
            'data' => $data,
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'last_page' => max(1, $lastPage),
            ],
        ];
    }

    public function blockDevice(string $id, string $blockedAt, ?string $blockedReason): void
    {
        Device::where('id', $id)->update([
            'status'         => 'blocked',
            'blocked_at'     => $blockedAt,
            'blocked_reason' => $blockedReason,
        ]);
    }

    public function unblockDevice(string $id): void
    {
        Device::where('id', $id)->update([
            'status'         => 'trusted',
            'blocked_at'     => null,
            'blocked_reason' => null,
        ]);
    }

    public function updateFingerprint(string $deviceId, string $newHash): void
    {
        Device::where('id', $deviceId)->update(['device_fingerprint' => $newHash]);
    }
}
