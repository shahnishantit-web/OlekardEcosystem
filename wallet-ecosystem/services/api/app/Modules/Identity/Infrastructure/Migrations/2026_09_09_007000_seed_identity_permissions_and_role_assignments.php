<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    protected $connection = 'identity';

    private function now(): string
    {
        return now()->toDateTimeString();
    }

    public function up(): void
    {
        $permissions = [
            ['slug' => 'admin.user.create',            'module' => 'identity', 'display_name' => 'Create Admin User'],
            ['slug' => 'admin.user.read',              'module' => 'identity', 'display_name' => 'Read Admin Users'],
            ['slug' => 'admin.user.deactivate',        'module' => 'identity', 'display_name' => 'Deactivate Admin User'],
            ['slug' => 'admin.role.read',              'module' => 'identity', 'display_name' => 'Read Roles'],
            ['slug' => 'admin.role.create',            'module' => 'identity', 'display_name' => 'Create Role'],
            ['slug' => 'admin.role.assign',            'module' => 'identity', 'display_name' => 'Assign/Remove Roles to Users'],
            ['slug' => 'admin.role.manage',            'module' => 'identity', 'display_name' => 'Manage Role Permission Assignments'],
            ['slug' => 'admin.permission.read',        'module' => 'identity', 'display_name' => 'Read Permissions'],
            ['slug' => 'configuration.policy.view',    'module' => 'platform', 'display_name' => 'View Platform Configuration'],
            ['slug' => 'configuration.policy.update',  'module' => 'platform', 'display_name' => 'Update Platform Configuration'],
            ['slug' => 'session.admin.revoke',         'module' => 'identity', 'display_name' => 'Administratively Revoke Sessions'],
            ['slug' => 'device.admin.read',            'module' => 'identity', 'display_name' => 'Read User Devices (Admin)'],
            ['slug' => 'device.admin.block',           'module' => 'identity', 'display_name' => 'Block User Devices (Admin)'],
            ['slug' => 'device.admin.remove',          'module' => 'identity', 'display_name' => 'Remove User Devices (Admin)'],
            ['slug' => 'device.admin.unblock',         'module' => 'identity', 'display_name' => 'Unblock User Devices (Admin)'],
        ];

        $now = $this->now();

        foreach ($permissions as $perm) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id'                    => (string) Str::orderedUuid(),
                'slug'                  => $perm['slug'],
                'display_name'          => $perm['display_name'],
                'description'           => null,
                'module'                => $perm['module'],
                'is_system_permission'  => true,
                'deleted_at'            => null,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);
        }

        $adminRole = DB::connection('identity')->table('identity.roles')
            ->where('slug', 'admin')
            ->first(['id']);

        if ($adminRole === null) {
            return;
        }

        $permissionRows = DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', array_column($permissions, 'slug'))
            ->get(['id']);

        foreach ($permissionRows as $permission) {
            DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                'id'                  => (string) Str::orderedUuid(),
                'role_id'             => $adminRole->id,
                'permission_id'       => $permission->id,
                'created_by_actor_id' => null,
                'created_at'          => $now,
            ]);
        }
    }

    public function down(): void
    {
        $slugs = [
            'admin.user.create', 'admin.user.read', 'admin.user.deactivate',
            'admin.role.read', 'admin.role.create', 'admin.role.assign', 'admin.role.manage',
            'admin.permission.read', 'configuration.policy.view', 'configuration.policy.update',
            'session.admin.revoke', 'device.admin.read', 'device.admin.block',
            'device.admin.remove', 'device.admin.unblock',
        ];

        $permissionIds = DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', $slugs)
            ->pluck('id');

        DB::connection('identity')->table('identity.role_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->delete();

        DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', $slugs)
            ->where('is_system_permission', true)
            ->delete();
    }
};
