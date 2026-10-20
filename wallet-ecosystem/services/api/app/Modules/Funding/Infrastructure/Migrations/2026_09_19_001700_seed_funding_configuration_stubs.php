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
            ['config_key' => 'funding.processing.timeout_minutes', 'config_value' => '60'],
            ['config_key' => 'funding.bank_reference.expiry_days',  'config_value' => '3'],
            ['config_key' => 'funding.idempotency.ttl_seconds',     'config_value' => '86400'],
            ['config_key' => 'funding.mock.success_rate',           'config_value' => '1.0'],
            // TBD-E08-004 / TBD-E07-001 — dev/test placeholder; replace with provisioned account ref on deploy
            ['config_key' => 'ledger.float_account.BWP',            'config_value' => 'ledger_float_pool_BWP'],
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
        DB::connection('platform')->table('platform.configuration_values')
            ->whereIn('config_key', [
                'funding.processing.timeout_minutes',
                'funding.bank_reference.expiry_days',
                'funding.idempotency.ttl_seconds',
                'funding.mock.success_rate',
                'ledger.float_account.BWP',
            ])
            ->delete();
    }
};
