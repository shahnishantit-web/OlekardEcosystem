<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Infrastructure\Models\Session;

interface SessionRepositoryInterface
{
    public function create(array $attributes): Session;

    public function findById(string $id): ?Session;

    public function findByRefreshHash(string $hash): ?Session;

    /** @return Session[] */
    public function findByFamilyId(string $familyId): array;

    public function updateStatus(string $id, string $status): void;

    public function revokeAllForUser(string $userId): void;

    public function revokeFamily(string $familyId): void;

    public function updateLastActivity(string $id): void;

    /** @return Session[] */
    public function listActiveForUser(string $userId): array;
}
