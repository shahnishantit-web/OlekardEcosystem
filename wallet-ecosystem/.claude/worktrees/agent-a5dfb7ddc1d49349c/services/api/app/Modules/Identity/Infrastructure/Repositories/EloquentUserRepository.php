<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Infrastructure\Models\User;

final class EloquentUserRepository implements UserRepositoryInterface
{
    public function findByMobile(string $mobileNumber): ?User
    {
        return User::where('mobile_number', $mobileNumber)->first();
    }

    public function findByEmail(string $email): ?User
    {
        return User::where('email', mb_strtolower($email))->first();
    }

    public function findById(string $id): ?User
    {
        return User::find($id);
    }

    public function create(array $attributes): User
    {
        return User::create($attributes);
    }

    public function updateStatus(string $id, string $status, array $extra = []): void
    {
        User::where('id', $id)->update(array_merge(['status' => $status], $extra));
    }

    public function incrementFailedAttempts(string $id): void
    {
        User::where('id', $id)->increment('failed_attempt_count');
    }

    public function resetFailedAttempts(string $id): void
    {
        User::where('id', $id)->update(['failed_attempt_count' => 0]);
    }

    public function incrementLockoutEpisode(string $id): void
    {
        User::where('id', $id)->increment('lockout_episode_count');
    }

    public function updateLockedAt(string $id, \DateTimeInterface $lockedAt): void
    {
        User::where('id', $id)->update(['locked_at' => $lockedAt->format('Y-m-d H:i:s')]);
    }

    public function updateLastLogin(string $id): void
    {
        User::where('id', $id)->update([
            'last_login_at'      => now(),
            'locked_at'          => null,
            'failed_attempt_count' => 0,
        ]);
    }
}
