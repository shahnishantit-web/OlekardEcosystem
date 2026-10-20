<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class OpsPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['slug' => 'ops.view_logs',           'description' => 'View structured application logs'],
            ['slug' => 'ops.view_metrics',         'description' => 'View service metrics and dashboards'],
            ['slug' => 'ops.view_health',          'description' => 'View provider health status'],
            ['slug' => 'ops.manage_alerts',        'description' => 'Create, update, and delete alert rules; view fired alerts'],
            ['slug' => 'ops.manage_backup',        'description' => 'Trigger and manage database backup jobs; view DR status'],
            ['slug' => 'ops.manage_deployments',   'description' => 'View deployment versions and initiate rollbacks'],
        ];

        foreach ($permissions as $permission) {
            DB::connection('identity')->table('identity.permissions')->upsert(
                $permission,
                ['slug'],
                ['description']
            );
        }
    }
}
