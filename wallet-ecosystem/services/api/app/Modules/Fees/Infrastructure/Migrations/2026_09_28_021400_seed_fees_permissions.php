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
            ['slug' => 'fees.rules.create', 'display_name' => 'Create Fee Rules (Operations Admin)'],
            ['slug' => 'fees.rules.view',   'display_name' => 'View Fee Rules'],
            ['slug' => 'fees.rules.update', 'display_name' => 'Update Fee Rules (Operations Admin)'],
            ['slug' => 'fees.rules.delete', 'display_name' => 'Deactivate Fee Rules (Operations Admin)'],
            ['slug' => 'fees.calculate',    'display_name' => 'Calculate Fee Preview'],
        ];

        $now = $this->now();

        foreach ($permissions as $perm) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id'                   => (string) Str::orderedUuid(),
                'slug'                 => $perm['slug'],
                'display_name'         => $perm['display_name'],
                'description'          => null,
                'module'               => 'fees',
                'is_system_permission' => true,
                'deleted_at'           => null,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
        }

        $allPermIds = DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', array_column($permissions, 'slug'))
            ->pluck('id', 'slug');

        // fees.rules.* → Operations Admin (admin role)
        $adminRoleId = DB::connection('identity')->table('identity.roles')
            ->where('slug', 'admin')
            ->value('id');

        $adminSlugs = ['fees.rules.create', 'fees.rules.view', 'fees.rules.update', 'fees.rules.delete', 'fees.calculate'];

        if ($adminRoleId !== null) {
            foreach ($adminSlugs as $slug) {
                if (isset($allPermIds[$slug])) {
                    DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                        'id'                  => (string) Str::orderedUuid(),
                        'role_id'             => $adminRoleId,
                        'permission_id'       => $allPermIds[$slug],
                        'created_by_actor_id' => null,
                        'created_at'          => $now,
                    ]);
                }
            }
        }

        // fees.calculate → agent role
        $agentRoleId = DB::connection('identity')->table('identity.roles')
            ->where('slug', 'agent')
            ->value('id');

        if ($agentRoleId !== null && isset($allPermIds['fees.calculate'])) {
            DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                'id'                  => (string) Str::orderedUuid(),
                'role_id'             => $agentRoleId,
                'permission_id'       => $allPermIds['fees.calculate'],
                'created_by_actor_id' => null,
                'created_at'          => $now,
            ]);
        }

        // fees.calculate → customer role
        $customerRoleId = DB::connection('identity')->table('identity.roles')
            ->where('slug', 'customer')
            ->value('id');

        if ($customerRoleId !== null && isset($allPermIds['fees.calculate'])) {
            DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                'id'                  => (string) Str::orderedUuid(),
                'role_id'             => $customerRoleId,
                'permission_id'       => $allPermIds['fees.calculate'],
                'created_by_actor_id' => null,
                'created_at'          => $now,
            ]);
        }
    }

    public function down(): void
    {
        $slugs = ['fees.rules.create', 'fees.rules.view', 'fees.rules.update', 'fees.rules.delete', 'fees.calculate'];

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
