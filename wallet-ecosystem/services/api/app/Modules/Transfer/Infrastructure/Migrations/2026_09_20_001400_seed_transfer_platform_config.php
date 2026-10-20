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
        $stubs = [
            ['config_key' => 'transfer.fee.rate_bps',              'config_value' => '0'],
            ['config_key' => 'transfer.min_amount',                'config_value' => '100'],
            ['config_key' => 'transfer.max_amount',                'config_value' => '500000'],
            ['config_key' => 'transfer.daily_limit',               'config_value' => '2000000'],
            ['config_key' => 'transfer.lookup_token_ttl_seconds',  'config_value' => '300'],
            ['config_key' => 'transfer.pending_ttl_minutes',       'config_value' => '10'],
            ['config_key' => 'transfer.lookup_rate_limit.per_minute', 'config_value' => '10'],
            ['config_key' => 'transfer.require_step_up_pin',       'config_value' => 'true'],
        ];

        foreach ($stubs as $stub) {
            DB::connection('platform')->table('platform.configuration_values')->insertOrIgnore([
                'id'           => (string) Uuid::uuid7(),
                'config_key'   => $stub['config_key'],
                'config_value' => $stub['config_value'],
                'value_type'   => 'string',
                'status'       => 'active',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    public function down(): void
    {
        $keys = [
            'transfer.fee.rate_bps',
            'transfer.min_amount',
            'transfer.max_amount',
            'transfer.daily_limit',
            'transfer.lookup_token_ttl_seconds',
            'transfer.pending_ttl_minutes',
            'transfer.lookup_rate_limit.per_minute',
            'transfer.require_step_up_pin',
        ];

        DB::connection('platform')->table('platform.configuration_values')
            ->whereIn('config_key', $keys)
            ->delete();
    }
};
