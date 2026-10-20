<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AdminPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'admin.read'                  => 'Read access to admin portal data',
            'admin.write'                 => 'Write access for admin actions (suspend, adjust, etc.)',
            'admin.freeze_wallet'         => 'Freeze and unfreeze wallets',
            'admin.approve_adjustment'    => 'Approve or reject wallet adjustments (maker-checker)',
            'admin.reverse_transaction'   => 'Reverse completed transactions',
            'admin.refund_transaction'    => 'Refund transactions',
            'admin.export_transactions'   => 'Export transaction data as CSV',
        ];

        // Find or create admin-officer role
        $role = DB::connection('identity')->table('identity.roles')
            ->where('slug', 'admin-officer')
            ->first(['id']);

        if ($role === null) {
            // Fallback: use the first admin role found
            $role = DB::connection('identity')->table('identity.roles')
                ->where('slug', 'admin')
                ->first(['id']);
        }

        foreach ($permissions as $slug => $description) {
            $existing = DB::connection('identity')->table('identity.permissions')
                ->where('slug', $slug)
                ->first(['id']);

            if ($existing !== null) {
                continue;
            }

            $permId = (string) Str::uuid7();
            DB::connection('identity')->table('identity.permissions')->insert([
                'id'          => $permId,
                'slug'        => $slug,
                'description' => $description,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            if ($role !== null) {
                $existing = DB::connection('identity')->table('identity.role_permissions')
                    ->where('role_id', $role->id)
                    ->where('permission_id', $permId)
                    ->exists();

                if (! $existing) {
                    DB::connection('identity')->table('identity.role_permissions')->insert([
                        'role_id'       => $role->id,
                        'permission_id' => $permId,
                        'created_at'    => now(),
                    ]);
                }
            }
        }
    }
}
