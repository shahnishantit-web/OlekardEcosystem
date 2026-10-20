<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Contracts\DeviceSecurityEventRepositoryInterface;
use App\Modules\Identity\Infrastructure\Models\DeviceSecurityEvent;
use Ramsey\Uuid\Uuid;

final class EloquentDeviceSecurityEventRepository implements DeviceSecurityEventRepositoryInterface
{
    public function create(array $attributes): DeviceSecurityEvent
    {
        if (! isset($attributes['id'])) {
            $attributes['id'] = (string) Uuid::uuid7();
        }

        return DeviceSecurityEvent::create($attributes);
    }

    public function findPendingForDevice(string $deviceId): ?DeviceSecurityEvent
    {
        return DeviceSecurityEvent::where('device_id', $deviceId)
            ->where('event_type', 'new_device_notified')
            ->whereNull('resolution')
            ->where('acknowledgment_expires_at', '>', now())
            ->orderByDesc('created_at')
            ->first();
    }

    public function findLatestNewDeviceEvent(string $deviceId): ?DeviceSecurityEvent
    {
        return DeviceSecurityEvent::where('device_id', $deviceId)
            ->where('event_type', 'new_device_notified')
            ->orderByDesc('created_at')
            ->first();
    }

    public function markResolved(string $id, string $resolution, string $resolvedAt): void
    {
        DeviceSecurityEvent::where('id', $id)->update([
            'resolution'  => $resolution,
            'resolved_at' => $resolvedAt,
            'updated_at'  => now(),
        ]);
    }

    public function listForDevice(string $deviceId, int $page, int $perPage): array
    {
        $paginator = DeviceSecurityEvent::where('device_id', $deviceId)
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data'  => $paginator->items(),
            'meta'  => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ];
    }
}
