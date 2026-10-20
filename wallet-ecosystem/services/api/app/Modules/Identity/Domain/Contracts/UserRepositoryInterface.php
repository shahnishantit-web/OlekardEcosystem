<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Infrastructure\Models\User;

interface UserRepositoryInterface
{
    public function findByMobile(string $mobileNumber): ?User;

    public function findByEmail(string $email): ?User;

    public function findById(string $id): ?User;

    public function create(array $attributes): User;

    public function updateStatus(string $id, string $status, array $extra = []): void;

    public function incrementFailedAttempts(string $id): void;

    public function resetFailedAttempts(string $id): void;

    public function incrementLockoutEpisode(string $id): void;

    public function updateLockedAt(string $id, \DateTimeInterface $lockedAt): void;

    public function updateLastLogin(string $id): void;

    public function updateContactInfo(string $userId, ?string $email, ?string $mobile): void;
}
