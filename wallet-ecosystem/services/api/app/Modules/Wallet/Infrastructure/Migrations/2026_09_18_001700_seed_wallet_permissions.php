<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    private const PERMISSIONS = [
        ['slug' => 'wallet.create',         'display_name' => 'Create Wallet',              'module' => 'wallet'],
        ['slug' => 'wallet.read',            'display_name' => 'Read Own Wallet',            'module' => 'wallet'],
        ['slug' => 'wallet.read.any',        'display_name' => 'Read Any Wallet',            'module' => 'wallet'],
        ['slug' => 'wallet.pocket.create',   'display_name' => 'Create Currency Pocket',     'module' => 'wallet'],
        ['slug' => 'wallet.limits.manage',   'display_name' => 'Manage Wallet Limits',       'module' => 'wallet'],
        ['slug' => 'wallet.freeze',          'display_name' => 'Freeze Wallet',              'module' => 'wallet'],
        ['slug' => 'wallet.unfreeze',        'display_name' => 'Unfreeze Wallet',            'module' => 'wallet'],
        ['slug' => 'wallet.close',           'display_name' => 'Close Wallet',               'module' => 'wallet'],
        ['slug' => 'wallet.status.manage',   'display_name' => 'Manage Wallet Status',       'module' => 'wallet'],
        ['slug' => 'wallet.balance.read',    'display_name' => 'Read Wallet Balance',        'module' => 'wallet'],
    ];

    public function up(): void
    {
        $now = now()->toIso8601String();

        foreach (self::PERMISSIONS as $perm) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id'         => (string) \Ramsey\Uuid\Uuid::uuid7(),
                'slug'       => $perm['slug'],
                'display_name' => $perm['display_name'],
                'module'     => $perm['module'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Assign Customer/Agent roles their read permissions
        $customerReadPerms = ['wallet.read', 'wallet.pocket.create', 'wallet.balance.read'];
        foreach ($customerReadPerms as $slug) {
            $permission = DB::connection('identity')->table('identity.permissions')->where('slug', $slug)->first();
            if (!$permission) {
                continue;
            }
            foreach (['CUSTOMER', 'AGENT'] as $roleSlug) {
                $role = DB::connection('identity')->table('identity.roles')->where('slug', strtolower($roleSlug))->first();
                if (!$role) {
                    continue;
                }
                DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                    'id'            => (string) \Ramsey\Uuid\Uuid::uuid7(),
                    'role_id'       => $role->id,
                    'permission_id' => $permission->id,
                    'created_at'    => $now,
                ]);
            }
        }

        // Assign Admin all 10 wallet permissions
        $adminRole = DB::connection('identity')->table('identity.roles')->where('slug', 'admin')->first();
        if ($adminRole) {
            foreach (self::PERMISSIONS as $perm) {
                $permission = DB::connection('identity')->table('identity.permissions')->where('slug', $perm['slug'])->first();
                if (!$permission) {
                    continue;
                }
                DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                    'id'            => (string) \Ramsey\Uuid\Uuid::uuid7(),
                    'role_id'       => $adminRole->id,
                    'permission_id' => $permission->id,
                    'created_at'    => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $slugs = array_column(self::PERMISSIONS, 'slug');
        $ids   = DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', $slugs)->pluck('id');

        DB::connection('identity')->table('identity.role_permissions')
            ->whereIn('permission_id', $ids)->delete();

        DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', $slugs)->delete();
    }
};
