<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Repositories\RolePermissionRepositoryInterface;
use App\Modules\Identity\Infrastructure\Models\RolePermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentRolePermissionRepository implements RolePermissionRepositoryInterface
{
    public function getPermissionSlugsForRoles(array $roleIds): array
    {
        if (empty($roleIds)) {
            return [];
        }

        return DB::connection('identity')
            ->table('identity.role_permissions as rp')
            ->join('identity.permissions as p', 'rp.permission_id', '=', 'p.id')
            ->whereIn('rp.role_id', $roleIds)
            ->whereNull('p.deleted_at')
            ->pluck('p.slug')
            ->all();
    }

    public function assignPermission(string $roleId, string $permissionId, string $assignedByActorId): void
    {
        DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
            'id'                  => (string) Str::orderedUuid(),
            'role_id'             => $roleId,
            'permission_id'       => $permissionId,
            'created_by_actor_id' => $assignedByActorId,
            'created_at'          => now()->toDateTimeString(),
        ]);
    }

    public function removePermission(string $roleId, string $permissionId): void
    {
        RolePermission::where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->delete();
    }

    public function hasPermission(string $roleId, string $permissionId): bool
    {
        return RolePermission::where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->exists();
    }
}
