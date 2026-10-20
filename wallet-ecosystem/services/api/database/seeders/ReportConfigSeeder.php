<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class ReportConfigSeeder extends Seeder
{
    public function run(): void
    {
        $now     = now();
        $configs = [
            ['key' => 'reports.max_rows_default',           'value' => '100000', 'type' => 'integer'],
            ['key' => 'reports.execution_timeout_seconds',  'value' => '300',    'type' => 'integer'],
            ['key' => 'reports.concurrent_jobs_per_user',   'value' => '3',      'type' => 'integer'],
            ['key' => 'reports.export_link_ttl_seconds',    'value' => '3600',   'type' => 'integer'],
            ['key' => 'reports.export_file_retention_days', 'value' => '7',      'type' => 'integer'],
            ['key' => 'reports.alert_failure_threshold',    'value' => '5',      'type' => 'integer'],
            ['key' => 'reports.alert_window_minutes',       'value' => '5',      'type' => 'integer'],
        ];

        foreach ($configs as $config) {
            DB::connection('platform')->table('platform.configuration_values')->upsert(
                [
                    'id'           => (string) Uuid::uuid7(),
                    'config_key'   => $config['key'],
                    'config_value' => $config['value'],
                    'value_type'   => $config['type'],
                    'status'       => 'active',
                    'version'      => 1,
                    'is_tbd'       => false,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ],
                ['config_key', 'version'],
                ['config_value', 'value_type', 'is_tbd', 'updated_at']
            );
        }
    }
}
