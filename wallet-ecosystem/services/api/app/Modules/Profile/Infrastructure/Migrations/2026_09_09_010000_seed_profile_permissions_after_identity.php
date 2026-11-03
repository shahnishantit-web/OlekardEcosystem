<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        // Require the full prerequisite schema rather than recording a silent no-op.
        foreach (['identity.permissions', 'identity.roles', 'identity.role_permissions'] as $table) {
            if (! \Illuminate\Support\Facades\Schema::connection('identity')->hasTable($table)) {
                throw new RuntimeException("Cannot seed Profile permissions before {$table} exists.");
            }
        }

        $this->seedProfilePermissions();
    }

    private function seedProfilePermissions(): void
    {
        $permissions = [
            ['slug' => 'profile.read', 'module' => 'profile', 'display_name' => 'Read User Profiles (Admin)'],
            ['slug' => 'profile.update', 'module' => 'profile', 'display_name' => 'Update User Profiles (Admin)'],
            ['slug' => 'account.reactivate', 'module' => 'profile', 'display_name' => 'Reactivate User Account'],
            ['slug' => 'account.close', 'module' => 'profile', 'display_name' => 'Permanently Close User Account'],
        ];

        $now = now()->toDateTimeString();
        foreach ($permissions as $permission) {
            \Illuminate\Support\Facades\DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id' => (string) \Illuminate\Support\Str::orderedUuid(),
                'slug' => $permission['slug'],
                'display_name' => $permission['display_name'],
                'description' => null,
                'module' => $permission['module'],
                'is_system_permission' => true,
                'deleted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $adminRole = \Illuminate\Support\Facades\DB::connection('identity')->table('identity.roles')
            ->where('slug', 'admin')->first(['id']);
        if ($adminRole === null) {
            return;
        }

        $permissionIds = \Illuminate\Support\Facades\DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', array_column($permissions, 'slug'))->get(['id']);
        foreach ($permissionIds as $permission) {
            \Illuminate\Support\Facades\DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                'id' => (string) \Illuminate\Support\Str::orderedUuid(),
                'role_id' => $adminRole->id,
                'permission_id' => $permission->id,
                'created_by_actor_id' => null,
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // The original Profile seed migration owns removal; this follow-up only
        // guarantees its effects happen after Identity's prerequisite schema.
    }
};
