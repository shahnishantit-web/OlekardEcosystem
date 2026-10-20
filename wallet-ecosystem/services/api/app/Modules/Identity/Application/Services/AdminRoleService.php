<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Repositories\PermissionRepositoryInterface;
use App\Modules\Identity\Domain\Repositories\RolePermissionRepositoryInterface;
use App\Modules\Identity\Domain\Repositories\RoleRepositoryInterface;
use App\Modules\Identity\Domain\Repositories\UserRoleRepositoryInterface;
use App\Modules\Identity\Domain\Services\PermissionResolverService;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AdminRoleService
{
    public function __construct(
        private readonly RoleRepositoryInterface $roles,
        private readonly PermissionRepositoryInterface $permissions,
        private readonly RolePermissionRepositoryInterface $rolePermissions,
        private readonly UserRoleRepositoryInterface $userRoles,
        private readonly PermissionResolverService $permissionResolver,
        private readonly AuditServiceInterface $audit,
    ) {}

    public function findAll(int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return Role::withCount(['rolePermissions'])
            ->whereNull('deleted_at')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function findById(string $id): ?Role
    {
        return Role::with(['rolePermissions.permission'])
            ->whereNull('deleted_at')
            ->find($id);
    }

    public function createRole(array $data, string $actorId, string $correlationId, string $ipHash = ''): Role
    {
        $existing = Role::withTrashed()->where('slug', $data['slug'])->first();

        if ($existing !== null) {
            throw new \DomainException('ROLE_SLUG_CONFLICT');
        }

        $role = $this->roles->create([
            'slug'           => $data['slug'],
            'display_name'   => $data['display_name'],
            'description'    => $data['description'] ?? null,
            'is_system_role' => false,
        ]);

        $this->audit->record(new AuditEventData(
            action:        'identity.role.created',
            actorType:     'ADMIN',
            resourceType:  'identity.role',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $role->id,
            afterState:    ['slug' => $role->slug, 'display_name' => $role->display_name],
            ipAddress:     $ipHash ?: null,
        ));

        return $role;
    }

    public function softDelete(string $id, string $actorId, string $correlationId, string $ipHash = ''): void
    {
        $role = $this->roles->findById($id);

        if ($role === null) {
            throw new \DomainException('ROLE_NOT_FOUND');
        }

        if ($role->is_system_role) {
            throw new \DomainException('SYSTEM_ROLE_DELETION_DENIED');
        }

        $this->roles->softDelete($id);

        $this->audit->record(new AuditEventData(
            action:        'identity.role.deleted',
            actorType:     'ADMIN',
            resourceType:  'identity.role',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $id,
            afterState:    ['slug' => $role->slug, 'deleted' => true],
            ipAddress:     $ipHash ?: null,
        ));
    }

    public function assignPermission(string $roleId, string $permissionId, string $actorId, string $correlationId, string $ipHash = ''): void
    {
        $role = $this->roles->findById($roleId);
        if ($role === null) {
            throw new \DomainException('ROLE_NOT_FOUND');
        }

        $permission = $this->permissions->findById($permissionId);
        if ($permission === null) {
            throw new \DomainException('PERMISSION_NOT_FOUND');
        }

        if ($this->rolePermissions->hasPermission($roleId, $permissionId)) {
            throw new \DomainException('PERMISSION_ALREADY_ON_ROLE');
        }

        $this->rolePermissions->assignPermission($roleId, $permissionId, $actorId);

        $this->audit->record(new AuditEventData(
            action:        'identity.role.permission.assigned',
            actorType:     'ADMIN',
            resourceType:  'identity.role',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $roleId,
            afterState:    ['permission_id' => $permissionId, 'permission_slug' => $permission->slug],
            ipAddress:     $ipHash ?: null,
        ));

        $this->invalidateCacheForRole($roleId);
    }

    public function removePermission(string $roleId, string $permissionId, string $actorId, string $correlationId, string $ipHash = ''): void
    {
        $role = $this->roles->findById($roleId);
        if ($role === null) {
            throw new \DomainException('ROLE_NOT_FOUND');
        }

        $permission = $this->permissions->findById($permissionId);

        if (!$this->rolePermissions->hasPermission($roleId, $permissionId)) {
            throw new \DomainException('PERMISSION_NOT_ON_ROLE');
        }

        $this->rolePermissions->removePermission($roleId, $permissionId);

        $this->audit->record(new AuditEventData(
            action:        'identity.role.permission.removed',
            actorType:     'ADMIN',
            resourceType:  'identity.role',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $roleId,
            afterState:    ['permission_id' => $permissionId, 'permission_slug' => $permission?->slug],
            ipAddress:     $ipHash ?: null,
        ));

        $this->invalidateCacheForRole($roleId);
    }

    private function invalidateCacheForRole(string $roleId): void
    {
        // Collect all users who hold this role (default_role_id + user_roles)
        $defaultRoleUserIds = DB::connection('identity')
            ->table('identity.users')
            ->where('default_role_id', $roleId)
            ->pluck('id')
            ->all();

        $additionalRoleUserIds = DB::connection('identity')
            ->table('identity.user_roles')
            ->where('role_id', $roleId)
            ->pluck('user_id')
            ->all();

        $affectedUserIds = array_unique(array_merge($defaultRoleUserIds, $additionalRoleUserIds));

        foreach ($affectedUserIds as $userId) {
            $this->permissionResolver->invalidateCache($userId);
        }
    }
}
