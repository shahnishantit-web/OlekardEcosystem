<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class ReconciliationPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['slug' => 'reconciliation.read',               'description' => 'View reconciliation batches, items, exceptions, and settlements'],
            ['slug' => 'reconciliation.write',              'description' => 'Import files, trigger matching, mark reconciled, manage exceptions'],
            ['slug' => 'reconciliation.approve_settlement', 'description' => 'Approve or reject settlement batches (checker role)'],
            ['slug' => 'reconciliation.execute_settlement', 'description' => 'Execute approved settlement batches'],
        ];

        foreach ($permissions as $permission) {
            DB::connection('identity')->table('identity.permissions')->upsert(
                $permission,
                ['slug'],
                ['description']
            );
        }

        $roles = [
            ['slug' => 'reconciliation-officer',  'permissions' => ['reconciliation.read', 'reconciliation.write']],
            ['slug' => 'settlement-approver',      'permissions' => ['reconciliation.read', 'reconciliation.write', 'reconciliation.approve_settlement']],
            ['slug' => 'settlement-executor',      'permissions' => ['reconciliation.read', 'reconciliation.execute_settlement']],
        ];

        foreach ($roles as $roleData) {
            $roleId = DB::connection('identity')
                ->table('identity.roles')
                ->where('slug', $roleData['slug'])
                ->value('id');

            if (! $roleId) {
                continue;
            }

            foreach ($roleData['permissions'] as $permSlug) {
                $permId = DB::connection('identity')
                    ->table('identity.permissions')
                    ->where('slug', $permSlug)
                    ->value('id');

                if (! $permId) {
                    continue;
                }

                DB::connection('identity')->table('identity.role_permissions')->upsert(
                    ['role_id' => $roleId, 'permission_id' => $permId],
                    ['role_id', 'permission_id']
                );
            }
        }
    }
}
