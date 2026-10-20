<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    protected $connection = 'ledger';

    private const PERMISSIONS = [
        ['slug' => 'ledger.post',             'display_name' => 'Post Journal Entry',           'module' => 'ledger'],
        ['slug' => 'ledger.read',             'display_name' => 'Read Own Ledger Account',       'module' => 'ledger'],
        ['slug' => 'ledger.read.any',         'display_name' => 'Read Any Ledger Account',       'module' => 'ledger'],
        ['slug' => 'ledger.account.register', 'display_name' => 'Register Ledger Account',       'module' => 'ledger'],
        ['slug' => 'ledger.entry.reverse',    'display_name' => 'Reverse Posted Journal Entry',  'module' => 'ledger'],
    ];

    // Assigned to standard admin role (SR-E07-006: ledger.entry.reverse NOT included)
    private const ADMIN_PERMISSION_SLUGS = [
        'ledger.post',
        'ledger.read',
        'ledger.read.any',
        'ledger.account.register',
    ];

    private function now(): string
    {
        return now()->toDateTimeString();
    }

    public function up(): void
    {
        $now = $this->now();

        foreach (self::PERMISSIONS as $perm) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id'                   => (string) Uuid::uuid7(),
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

        $allPermIds = DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', array_column(self::PERMISSIONS, 'slug'))
            ->pluck('id', 'slug');

        $adminRole = DB::connection('identity')->table('identity.roles')
            ->where('slug', 'admin')
            ->first(['id']);

        if ($adminRole !== null) {
            foreach (self::ADMIN_PERMISSION_SLUGS as $slug) {
                $permId = $allPermIds[$slug] ?? null;
                if ($permId === null) {
                    continue;
                }
                DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                    'id'                  => (string) Uuid::uuid7(),
                    'role_id'             => $adminRole->id,
                    'permission_id'       => $permId,
                    'created_by_actor_id' => null,
                    'created_at'          => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $slugs = array_column(self::PERMISSIONS, 'slug');

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
