<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Populates minimal test data for E19 Admin Portal quickstart validation.
 *
 * ONLY runs in local/testing environments (App::isLocal() guard).
 * Requires E01–E12 seeders to have run first (identity.users, wallet.wallets, etc.)
 *
 * Run: php artisan db:seed --class=AdminPortalTestSeeder
 */
final class AdminPortalTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->isLocal() && ! app()->environment('testing')) {
            $this->command->warn('AdminPortalTestSeeder: skipped — only runs in local/testing environments');
            return;
        }

        $this->seedAdminPermissions();
        $this->seedTestAdjustment();
        $this->command->info('AdminPortalTestSeeder: complete');
    }

    private function seedAdminPermissions(): void
    {
        // Ensure admin user has all required admin.* permissions
        $adminUser = DB::connection('identity')->table('identity.users')
            ->where('email', 'admin@olekard.local')
            ->first();

        if ($adminUser === null) {
            $this->command->warn('admin@olekard.local not found — run AdminSeeder first');
            return;
        }

        $permissions = [
            'admin.read',
            'admin.write',
            'admin.freeze_wallet',
            'admin.approve_adjustment',
            'admin.reverse_transaction',
            'admin.refund_transaction',
            'admin.export_transactions',
        ];

        foreach ($permissions as $slug) {
            $permission = DB::connection('identity')->table('identity.permissions')
                ->where('slug', $slug)
                ->first();

            if ($permission === null) {
                $this->command->warn("Permission [{$slug}] not found — run AdminPermissionSeeder first");
                continue;
            }

            // Assign to admin user's role via role_permissions
            $adminRole = DB::connection('identity')->table('identity.user_roles')
                ->where('user_id', $adminUser->id)
                ->first();

            if ($adminRole === null) {
                continue;
            }

            $exists = DB::connection('identity')->table('identity.role_permissions')
                ->where('role_id', $adminRole->role_id)
                ->where('permission_id', $permission->id)
                ->exists();

            if (! $exists) {
                DB::connection('identity')->table('identity.role_permissions')->insert([
                    'role_id'       => $adminRole->role_id,
                    'permission_id' => $permission->id,
                    'created_at'    => now(),
                ]);
            }
        }

        $this->command->info('Admin permissions assigned');
    }

    private function seedTestAdjustment(): void
    {
        // Seed a pending adjustment for the quickstart SC-7/SC-8 tests
        $adminUser = DB::connection('identity')->table('identity.users')
            ->where('email', 'admin@olekard.local')
            ->first();

        if ($adminUser === null) {
            return;
        }

        $wallet = DB::connection('wallet')->table('wallet.wallets')
            ->where('owner_actor_id', $adminUser->id)
            ->first();

        if ($wallet === null) {
            $this->command->warn('No wallet found for admin user — skipping test adjustment seed');
            return;
        }

        $exists = DB::connection('admin')->table('admin.admin_wallet_adjustments')
            ->where('idempotency_key', 'quickstart-test-adj-001')
            ->exists();

        if (! $exists) {
            DB::connection('admin')->table('admin.admin_wallet_adjustments')->insert([
                'id'              => (string) Uuid::uuid7(),
                'idempotency_key' => 'quickstart-test-adj-001',
                'wallet_id'       => $wallet->id,
                'adjustment_type' => 'credit',
                'amount'          => '50.00',
                'currency'        => 'BWP',
                'reason'          => 'Quickstart test credit adjustment for E19 validation',
                'status'          => 'pending_approval',
                'maker_id'        => $adminUser->id,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            $this->command->info('Test adjustment seeded (quickstart-test-adj-001)');
        }
    }
}
