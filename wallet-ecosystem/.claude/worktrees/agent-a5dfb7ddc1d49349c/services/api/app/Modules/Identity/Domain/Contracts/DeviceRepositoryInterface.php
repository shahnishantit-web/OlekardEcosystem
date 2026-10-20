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
}
