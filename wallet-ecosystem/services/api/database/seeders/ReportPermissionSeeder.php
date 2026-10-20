<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class ReportPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['slug' => 'finance.reports',     'description' => 'Access to financial and reconciliation reports'],
            ['slug' => 'compliance.reports',  'description' => 'Access to KYC and risk compliance reports'],
            ['slug' => 'reports.schedule',    'description' => 'Create and manage recurring report schedules'],
            ['slug' => 'compliance.pii.read', 'description' => 'Access to unmasked PII fields in reports'],
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
