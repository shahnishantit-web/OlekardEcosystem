<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    private const CONFIG_KEYS = [
        // TBD-E06-004: hold expiry schedule
        ['config_key' => 'wallet.expire_holds_schedule', 'config_value' => null,   'is_tbd' => true,  'description' => 'Cron schedule for wallet:expire-holds command (TBD-E06-004)'],
        // TBD-E06-007: wallet reference format
        ['config_key' => 'wallet.reference_format',      'config_value' => null,   'is_tbd' => true,  'description' => 'Wallet reference format string (TBD-E06-007)'],
        // TBD-E06-008: eager vs lazy pocket creation; 'lazy' is a safe operational default
        ['config_key' => 'wallet.pocket_creation_strategy', 'config_value' => 'lazy', 'is_tbd' => true, 'description' => 'Pocket creation strategy: lazy (BWP only at provision) or eager (TBD-E06-008)'],
        // Wallet limit defaults — all TBD-E06-004
        ['config_key' => 'wallet.limit.daily_transaction_amount.default',   'config_value' => null, 'is_tbd' => true, 'description' => 'Default daily transaction amount limit (TBD-E06-004)'],
        ['config_key' => 'wallet.limit.weekly_transaction_amount.default',  'config_value' => null, 'is_tbd' => true, 'description' => 'Default weekly transaction amount limit (TBD-E06-004)'],
        ['config_key' => 'wallet.limit.monthly_transaction_amount.default', 'config_value' => null, 'is_tbd' => true, 'description' => 'Default monthly transaction amount limit (TBD-E06-004)'],
        ['config_key' => 'wallet.limit.single_transaction_amount.default',  'config_value' => null, 'is_tbd' => true, 'description' => 'Default single transaction amount limit (TBD-E06-004)'],
        ['config_key' => 'wallet.limit.max_balance.default',                'config_value' => null, 'is_tbd' => true, 'description' => 'Default maximum wallet balance limit (TBD-E06-004)'],
    ];

    public function up(): void
    {
        $now = now()->toIso8601String();

        foreach (self::CONFIG_KEYS as $config) {
            DB::connection('platform')->table('platform.configuration_values')->insertOrIgnore([
                'id'           => (string) \Ramsey\Uuid\Uuid::uuid7(),
                'config_key'   => $config['config_key'],
                'config_value' => $config['config_value'],
                'value_type'   => 'string',
                'status'       => 'active',
                'is_tbd'       => $config['is_tbd'],
                'description'  => $config['description'],
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
    }

    public function down(): void
    {
        $keys = array_column(self::CONFIG_KEYS, 'config_key');
        DB::connection('platform')->table('platform.configuration_values')
            ->whereIn('config_key', $keys)->delete();
    }
};
