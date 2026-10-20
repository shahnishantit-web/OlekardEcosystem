<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Infrastructure\Models\Device;

interface DeviceRepositoryInterface
{
    public function findById(string $id): ?Device;

    public function findByUserAndFingerprint(string $userId, string $fingerprintHash): ?Device;

    public function create(array $attributes): Device;

    public function updateStatus(string $id, string $status, array $extra = []): void;

    /** @return Device[] */
    public function listForUser(string $userId): array;

    public function updateLastSeen(string $id): void;

    /** @return Device[] */
    public function findByFingerprint(string $fingerprintHash): array;

    public function countForUser(string $userId): int;

    /**
     * @return array{data: Device[], meta: array{total: int, page: int, per_page: int, last_page: int}}
     */
    public function listForUserPaginated(string $userId, int $page, int $perPage, ?string $status = null, ?string $platform = null): array;

    public function blockDevice(string $id, string $blockedAt, ?string $blockedReason): void;

    public function unblockDevice(string $id): void;

    public function updateFingerprint(string $deviceId, string $newHash): void;
}
