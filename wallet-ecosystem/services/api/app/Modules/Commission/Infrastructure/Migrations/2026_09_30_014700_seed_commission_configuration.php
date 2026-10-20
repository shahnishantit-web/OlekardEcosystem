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
            // TBD-E14-001 — dev/test placeholder; replace with provisioned expense account ref on deploy
            ['config_key' => 'ledger.commission_expense_account.BWP', 'config_value' => 'ledger_commission_expense_BWP'],
        ];

        foreach ($configs as $config) {
            DB::connection('platform')->table('platform.configuration_values')->insertOrIgnore([
                'id'           => (string) Uuid::uuid7(),
                'config_key'   => $config['config_key'],
                'config_value' => $config['config_value'],
                'value_type'   => 'string',
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
                'ledger.commission_expense_account.BWP',
            ])
            ->delete();
    }
};
