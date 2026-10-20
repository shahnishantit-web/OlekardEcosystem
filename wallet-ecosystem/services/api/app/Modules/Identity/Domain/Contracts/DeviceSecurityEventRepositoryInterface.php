<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Infrastructure\Models\DeviceSecurityEvent;

interface DeviceSecurityEventRepositoryInterface
{
    public function create(array $attributes): DeviceSecurityEvent;

    public function findPendingForDevice(string $deviceId): ?DeviceSecurityEvent;

    /**
     * Find the most recent new_device_notified event for the device, regardless of resolution or expiry.
     * Used to distinguish DEVICE_ALREADY_ACKNOWLEDGED from ACKNOWLEDGMENT_WINDOW_EXPIRED.
     */
    public function findLatestNewDeviceEvent(string $deviceId): ?DeviceSecurityEvent;

    public function markResolved(string $id, string $resolution, string $resolvedAt): void;

    public function listForDevice(string $deviceId, int $page, int $perPage): array;
}
