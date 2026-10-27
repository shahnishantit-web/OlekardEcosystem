<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommunityFuneralPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'community.funeral.manage',
            'community.funeral.review',
            'community.funeral.household.read',
            'community.funeral.report',
            'community.funeral.payout.request',
        ];
        $identity = DB::connection('identity');
        foreach ($permissions as $slug) {
            $identity->table('identity.permissions')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'slug' => $slug,
                'display_name' => Str::headline(str_replace('.', ' ', $slug)),
                'module' => 'community', 'is_system_permission' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $assignments = [
            'admin' => $permissions,
            'customer' => ['community.funeral.household.read'],
        ];
        foreach ($assignments as $roleSlug => $slugs) {
            $roleId = $identity->table('identity.roles')->where('slug', $roleSlug)->whereNull('deleted_at')->value('id');
            if ($roleId === null) {
                continue;
            }
            foreach ($slugs as $slug) {
                $permissionId = $identity->table('identity.permissions')->where('slug', $slug)->value('id');
                $identity->table('identity.role_permissions')->insertOrIgnore([
                    'id' => (string) Str::uuid7(), 'role_id' => $roleId,
                    'permission_id' => $permissionId, 'created_at' => now(),
                ]);
            }
        }
    }
}
