<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class AdminAppPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Dashboards
            ['slug' => 'adminapp.dashboard.operational', 'description' => 'View operational dashboard metrics'],
            ['slug' => 'adminapp.dashboard.financial',   'description' => 'View financial dashboard metrics'],
            ['slug' => 'adminapp.dashboard.risk',        'description' => 'View risk dashboard metrics'],
            // Users
            ['slug' => 'adminapp.users.read',    'description' => 'Search and view users'],
            ['slug' => 'adminapp.users.write',   'description' => 'Suspend and reactivate user accounts'],
            ['slug' => 'adminapp.roles.manage',  'description' => 'View and update admin role permissions'],
            // KYC
            ['slug' => 'adminapp.kyc.review',  'description' => 'Review KYC submission queue'],
            ['slug' => 'adminapp.kyc.approve', 'description' => 'Submit KYC approval (maker-checker)'],
            ['slug' => 'adminapp.kyc.reject',  'description' => 'Submit KYC rejection (maker-checker)'],
            // Wallets
            ['slug' => 'adminapp.wallets.read',     'description' => 'Search and view wallets'],
            ['slug' => 'adminapp.wallets.freeze',   'description' => 'Freeze wallet (maker-checker)'],
            ['slug' => 'adminapp.wallets.unfreeze', 'description' => 'Unfreeze wallet (maker-checker)'],
            // Transactions
            ['slug' => 'adminapp.transactions.read',        'description' => 'Search and view transactions'],
            ['slug' => 'adminapp.transactions.investigate', 'description' => 'Investigate transaction detail with ledger entries'],
            ['slug' => 'adminapp.transactions.reverse',     'description' => 'Reverse eligible transaction (maker-checker)'],
            // Agents
            ['slug' => 'adminapp.agents.approve', 'description' => 'Approve pending agent application (maker-checker)'],
            ['slug' => 'adminapp.agents.manage',  'description' => 'View, suspend, and reactivate agents'],
            ['slug' => 'adminapp.float.manage',   'description' => 'View and adjust agent float balances'],
            // Finance
            ['slug' => 'adminapp.reconciliation.read', 'description' => 'View reconciliation batches'],
            ['slug' => 'adminapp.settlement.read',     'description' => 'View settlement records'],
            // Compliance
            ['slug' => 'adminapp.audit.read',   'description' => 'Query immutable audit log'],
            ['slug' => 'adminapp.reports.read', 'description' => 'Request and download reports'],
        ];

        foreach ($permissions as $permission) {
            DB::connection('identity')->table('identity.permissions')->upsert(
                $permission,
                ['slug'],
                ['description'],
            );
        }

        // Assign permissions to roles per the role-to-story matrix
        $this->assignRolePermissions();
    }

    private function assignRolePermissions(): void
    {
        $roleMatrix = [
            'admin' => [
                'adminapp.dashboard.operational',
                'adminapp.dashboard.financial',
                'adminapp.dashboard.risk',
                'adminapp.users.read',
                'adminapp.users.write',
                'adminapp.roles.manage',
                'adminapp.kyc.review',
                'adminapp.kyc.approve',
                'adminapp.kyc.reject',
                'adminapp.wallets.read',
                'adminapp.wallets.freeze',
                'adminapp.wallets.unfreeze',
                'adminapp.transactions.read',
                'adminapp.transactions.investigate',
                'adminapp.transactions.reverse',
                'adminapp.agents.approve',
                'adminapp.agents.manage',
                'adminapp.float.manage',
                'adminapp.reconciliation.read',
                'adminapp.settlement.read',
                'adminapp.audit.read',
                'adminapp.reports.read',
            ],
            'operations' => [
                'adminapp.dashboard.operational',
                'adminapp.users.read',
                'adminapp.users.write',
                'adminapp.wallets.read',
                'adminapp.wallets.freeze',
                'adminapp.wallets.unfreeze',
                'adminapp.transactions.read',
                'adminapp.transactions.investigate',
                'adminapp.transactions.reverse',
                'adminapp.agents.approve',
                'adminapp.agents.manage',
            ],
            'finance' => [
                'adminapp.dashboard.financial',
                'adminapp.float.manage',
                'adminapp.reconciliation.read',
                'adminapp.settlement.read',
                'adminapp.reports.read',
                'adminapp.transactions.read',
            ],
            'compliance' => [
                'adminapp.dashboard.risk',
                'adminapp.kyc.review',
                'adminapp.kyc.approve',
                'adminapp.kyc.reject',
                'adminapp.audit.read',
                'adminapp.reports.read',
            ],
        ];

        foreach ($roleMatrix as $roleSlug => $permissionSlugs) {
            $role = DB::connection('identity')
                ->table('identity.roles')
                ->where('slug', $roleSlug)
                ->first(['id']);

            if ($role === null) {
                continue;
            }

            foreach ($permissionSlugs as $slug) {
                $permission = DB::connection('identity')
                    ->table('identity.permissions')
                    ->where('slug', $slug)
                    ->first(['id']);

                if ($permission === null) {
                    continue;
                }

                DB::connection('identity')->table('identity.role_permissions')->updateOrInsert(
                    ['role_id' => $role->id, 'permission_id' => $permission->id],
                    ['role_id' => $role->id, 'permission_id' => $permission->id],
                );
            }
        }
    }
}
