<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

// TBD-E07-001: System account references are null stubs. Values must be
// populated via a separate seeder before E08 is deployed.
return new class extends Migration
{
    protected $connection = 'ledger';

    private const SYSTEM_ACCOUNT_KEYS = [
        'ledger.system.float_account_ref'      => 'Float account ledger_account_reference (TBD-E07-001)',
        'ledger.system.fee_account_ref'        => 'Fee collection account ledger_account_reference (TBD-E07-001)',
        'ledger.system.revenue_account_ref'    => 'Revenue account ledger_account_reference (TBD-E07-001)',
        'ledger.system.commission_account_ref' => 'Commission account ledger_account_reference (TBD-E07-001)',
        'ledger.system.suspense_account_ref'   => 'Suspense account ledger_account_reference (TBD-E07-001)',
    ];

    public function up(): void
    {
        $now = now()->toDateTimeString();

        foreach (self::SYSTEM_ACCOUNT_KEYS as $key => $description) {
            DB::connection('platform')->table('platform.configuration_values')->insertOrIgnore([
                'id'              => (string) Uuid::uuid7(),
                'config_key'      => $key,
                'config_value'    => null,
                'value_type'      => 'string',
                'status'          => 'active',
                'version'         => 1,
                'is_tbd'          => true,
                'description'     => $description,
                'effective_from'  => null,
                'effective_until' => null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('platform')->table('platform.configuration_values')
            ->whereIn('config_key', array_keys(self::SYSTEM_ACCOUNT_KEYS))
            ->where('version', 1)
            ->delete();
    }
};
