<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Repositories;

interface RolePermissionRepositoryInterface
{
    /** Returns all permission slugs for the given role IDs in a single query. */
    public function getPermissionSlugsForRoles(array $roleIds): array;

    public function assignPermission(string $roleId, string $permissionId, string $assignedByActorId): void;

    public function removePermission(string $roleId, string $permissionId): void;

    public function hasPermission(string $roleId, string $permissionId): bool;
}
