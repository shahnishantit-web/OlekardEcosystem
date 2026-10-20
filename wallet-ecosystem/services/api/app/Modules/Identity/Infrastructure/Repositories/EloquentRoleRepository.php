<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Repositories\RoleRepositoryInterface;
use App\Modules\Identity\Infrastructure\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentRoleRepository implements RoleRepositoryInterface
{
    public function findAll(): Collection
    {
        return Role::whereNull('deleted_at')->get();
    }

    public function findById(string $id): ?Role
    {
        return Role::find($id);
    }

    public function findBySlug(string $slug): ?Role
    {
        return Role::withTrashed()->where('slug', $slug)->first();
    }

    public function create(array $data): Role
    {
        return Role::create($data);
    }

    public function softDelete(string $id): void
    {
        Role::where('id', $id)->delete();
    }

    public function getActorRoleSlugs(string $actorId): array
    {
        $user = DB::connection('identity')
            ->table('identity.users')
            ->where('id', $actorId)
            ->first(['default_role_id']);

        if ($user === null) {
            return [];
        }

        $roleIds = array_filter([$user->default_role_id ?? null]);

        $additionalRoleIds = DB::connection('identity')
            ->table('identity.user_roles')
            ->where('user_id', $actorId)
            ->pluck('role_id')
            ->all();

        $allRoleIds = array_unique(array_merge($roleIds, $additionalRoleIds));

        if (empty($allRoleIds)) {
            return [];
        }

        return DB::connection('identity')
            ->table('identity.roles')
            ->whereIn('id', $allRoleIds)
            ->whereNull('deleted_at')
            ->pluck('slug')
            ->all();
    }
}
