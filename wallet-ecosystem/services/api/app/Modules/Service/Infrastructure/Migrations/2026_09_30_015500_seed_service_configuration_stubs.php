<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        $configs = [
            ['config_key' => 'service.payment.validation_timeout_seconds',    'config_value' => '10',  'value_type' => 'integer'],
            ['config_key' => 'service.payment.provider_timeout_seconds',       'config_value' => '30',  'value_type' => 'integer'],
            ['config_key' => 'service.payment.requery_interval_seconds',       'config_value' => '300', 'value_type' => 'integer'],
            ['config_key' => 'service.payment.requery_max_attempts',           'config_value' => '12',  'value_type' => 'integer'],
            ['config_key' => 'service.payment.stale_initiated_timeout_minutes','config_value' => '30',  'value_type' => 'integer'],
        ];

        foreach ($configs as $config) {
            DB::connection('platform')->table('platform.configuration_values')->insertOrIgnore([
                'id'           => (string) Uuid::uuid7(),
                'config_key'   => $config['config_key'],
                'config_value' => $config['config_value'],
                'value_type'   => $config['value_type'],
                'status'       => 'active',
                'version'      => 1,
                'is_tbd'       => false,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('platform')->table('platform.configuration_values')
            ->whereIn('config_key', [
                'service.payment.validation_timeout_seconds',
                'service.payment.provider_timeout_seconds',
                'service.payment.requery_interval_seconds',
                'service.payment.requery_max_attempts',
                'service.payment.stale_initiated_timeout_minutes',
            ])
            ->delete();
    }
};
