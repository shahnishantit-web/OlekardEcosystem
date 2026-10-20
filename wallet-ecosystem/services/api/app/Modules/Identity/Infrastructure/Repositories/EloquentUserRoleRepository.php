<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Repositories\UserRoleRepositoryInterface;
use App\Modules\Identity\Infrastructure\Models\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentUserRoleRepository implements UserRoleRepositoryInterface
{
    public function getActorRoleIds(string $actorId): array
    {
        return UserRole::where('user_id', $actorId)
            ->pluck('role_id')
            ->all();
    }

    public function assignRole(string $userId, string $roleId, string $assignedByActorId): void
    {
        DB::connection('identity')->table('identity.user_roles')->insertOrIgnore([
            'id'                  => (string) Str::orderedUuid(),
            'user_id'             => $userId,
            'role_id'             => $roleId,
            'created_by_actor_id' => $assignedByActorId,
            'created_at'          => now()->toDateTimeString(),
        ]);
    }

    public function removeRole(string $userId, string $roleId): void
    {
        UserRole::where('user_id', $userId)->where('role_id', $roleId)->delete();
    }
}
