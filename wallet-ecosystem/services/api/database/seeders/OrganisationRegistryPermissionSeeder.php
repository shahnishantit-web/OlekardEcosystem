<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines permissions only. A security administrator must assign request/review/revoke separately. */
final class OrganisationRegistryPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $db = DB::connection('identity');
        foreach (['organisation.registry.request', 'organisation.registry.review', 'organisation.registry.revoke'] as $slug) {
            $db->table('identity.permissions')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'slug' => $slug,
                'display_name' => Str::headline(str_replace('.', ' ', $slug)),
                'module' => 'identity', 'is_system_permission' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
