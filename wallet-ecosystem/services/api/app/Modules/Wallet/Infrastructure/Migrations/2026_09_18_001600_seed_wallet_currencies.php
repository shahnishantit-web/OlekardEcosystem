<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'wallet';

    public function up(): void
    {
        $now = now()->toIso8601String();

        DB::connection('wallet')->table('wallet.currencies')->insert([
            ['code' => 'BWP', 'name' => 'Botswana Pula',        'precision' => 2,    'is_enabled' => true, 'is_primary' => true,  'is_tbd' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'USD', 'name' => 'US Dollar',            'precision' => 2,    'is_enabled' => true, 'is_primary' => false, 'is_tbd' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'EUR', 'name' => 'Euro',                 'precision' => 2,    'is_enabled' => true, 'is_primary' => false, 'is_tbd' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'ZAR', 'name' => 'South African Rand',   'precision' => 2,    'is_enabled' => true, 'is_primary' => false, 'is_tbd' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CNY', 'name' => 'Chinese Yuan',         'precision' => 2,    'is_enabled' => true, 'is_primary' => false, 'is_tbd' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'USDT','name' => 'Tether USD',           'precision' => null, 'is_enabled' => true, 'is_primary' => false, 'is_tbd' => true,  'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        DB::connection('wallet')->table('wallet.currencies')
            ->whereIn('code', ['BWP', 'USD', 'EUR', 'ZAR', 'CNY', 'USDT'])
            ->delete();
    }
};
