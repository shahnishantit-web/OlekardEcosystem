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
            // Fee rates (basis points; 0 = no fee)
            ['config_key' => 'withdrawal.fee.mobile_money.BWP',  'config_value' => '0'],
            ['config_key' => 'withdrawal.fee.bank_transfer.BWP',  'config_value' => '0'],

            // Per-transaction limits — mobile money, tier1
            ['config_key' => 'withdrawal.limit.mobile_money.tier1.min_amount', 'config_value' => '100'],
            ['config_key' => 'withdrawal.limit.mobile_money.tier1.max_amount', 'config_value' => '1000000'],
            ['config_key' => 'withdrawal.limit.mobile_money.tier1.daily_max',  'config_value' => '5000000'],

            // Per-transaction limits — mobile money, tier2
            ['config_key' => 'withdrawal.limit.mobile_money.tier2.min_amount', 'config_value' => '100'],
            ['config_key' => 'withdrawal.limit.mobile_money.tier2.max_amount', 'config_value' => '1000000'],
            ['config_key' => 'withdrawal.limit.mobile_money.tier2.daily_max',  'config_value' => '5000000'],

            // Per-transaction limits — bank transfer, tier1
            ['config_key' => 'withdrawal.limit.bank_transfer.tier1.min_amount', 'config_value' => '100'],
            ['config_key' => 'withdrawal.limit.bank_transfer.tier1.max_amount', 'config_value' => '1000000'],
            ['config_key' => 'withdrawal.limit.bank_transfer.tier1.daily_max',  'config_value' => '5000000'],

            // Per-transaction limits — bank transfer, tier2
            ['config_key' => 'withdrawal.limit.bank_transfer.tier2.min_amount', 'config_value' => '100'],
            ['config_key' => 'withdrawal.limit.bank_transfer.tier2.max_amount', 'config_value' => '1000000'],
            ['config_key' => 'withdrawal.limit.bank_transfer.tier2.daily_max',  'config_value' => '5000000'],

            // Hold TTL
            ['config_key' => 'withdrawal.hold_ttl_minutes', 'config_value' => '60'],

            // Step-up threshold (minor units; 0 = always require step-up)
            ['config_key' => 'withdrawal.step_up_threshold', 'config_value' => '0'],

            // Available currencies per channel (comma-separated)
            ['config_key' => 'withdrawal.channel.mobile_money.available_currencies',  'config_value' => 'BWP'],
            ['config_key' => 'withdrawal.channel.bank_transfer.available_currencies', 'config_value' => 'BWP'],

            // Ledger transit and fee revenue account references (TBD-E10-004; placeholder)
            ['config_key' => 'ledger.payout_transit.BWP', 'config_value' => 'tbd-payout-transit-bwp'],
            ['config_key' => 'ledger.fee_revenue.BWP',    'config_value' => 'tbd-fee-revenue-bwp'],
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
            'withdrawal.fee.mobile_money.BWP',
            'withdrawal.fee.bank_transfer.BWP',
            'withdrawal.limit.mobile_money.tier1.min_amount',
            'withdrawal.limit.mobile_money.tier1.max_amount',
            'withdrawal.limit.mobile_money.tier1.daily_max',
            'withdrawal.limit.mobile_money.tier2.min_amount',
            'withdrawal.limit.mobile_money.tier2.max_amount',
            'withdrawal.limit.mobile_money.tier2.daily_max',
            'withdrawal.limit.bank_transfer.tier1.min_amount',
            'withdrawal.limit.bank_transfer.tier1.max_amount',
            'withdrawal.limit.bank_transfer.tier1.daily_max',
            'withdrawal.limit.bank_transfer.tier2.min_amount',
            'withdrawal.limit.bank_transfer.tier2.max_amount',
            'withdrawal.limit.bank_transfer.tier2.daily_max',
            'withdrawal.hold_ttl_minutes',
            'withdrawal.step_up_threshold',
            'withdrawal.channel.mobile_money.available_currencies',
            'withdrawal.channel.bank_transfer.available_currencies',
            'ledger.payout_transit.BWP',
            'ledger.fee_revenue.BWP',
        ];

        DB::connection('platform')->table('platform.configuration_values')
            ->whereIn('config_key', $keys)
            ->delete();
    }
};
