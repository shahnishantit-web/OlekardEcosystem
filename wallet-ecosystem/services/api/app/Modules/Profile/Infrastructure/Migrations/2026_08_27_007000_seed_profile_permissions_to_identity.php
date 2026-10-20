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
            ['slug' => 'profile.read',         'module' => 'profile', 'display_name' => 'Read User Profiles (Admin)'],
            ['slug' => 'profile.update',        'module' => 'profile', 'display_name' => 'Update User Profiles (Admin)'],
            ['slug' => 'account.reactivate',    'module' => 'profile', 'display_name' => 'Reactivate User Account'],
            ['slug' => 'account.close',         'module' => 'profile', 'display_name' => 'Permanently Close User Account'],
        ];

        $now = $this->now();

        foreach ($permissions as $perm) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id'                   => (string) Str::orderedUuid(),
                'slug'                 => $perm['slug'],
                'display_name'         => $perm['display_name'],
                'description'          => null,
                'module'               => $perm['module'],
                'is_system_permission' => true,
                'deleted_at'           => null,
                'created_at'           => $now,
                'updated_at'           => $now,
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
        $slugs = ['profile.read', 'profile.update', 'account.reactivate', 'account.close'];

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
