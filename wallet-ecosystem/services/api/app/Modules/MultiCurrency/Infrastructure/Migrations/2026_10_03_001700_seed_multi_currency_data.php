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
        $now = now()->toIso8601String();

        // Seed initial active currencies
        $currencies = [
            ['code' => 'BWP', 'name' => 'Botswana Pula',   'symbol' => 'P',  'decimal_places' => 2],
            ['code' => 'USD', 'name' => 'US Dollar',        'symbol' => '$',  'decimal_places' => 2],
            ['code' => 'ZAR', 'name' => 'South African Rand', 'symbol' => 'R', 'decimal_places' => 2],
            ['code' => 'EUR', 'name' => 'Euro',              'symbol' => '€',  'decimal_places' => 2],
            ['code' => 'GBP', 'name' => 'British Pound',     'symbol' => '£',  'decimal_places' => 2],
            ['code' => 'ZMW', 'name' => 'Zambian Kwacha',    'symbol' => 'ZK', 'decimal_places' => 2],
        ];

        foreach ($currencies as $currency) {
            DB::connection('platform')->table('multi_currency.currencies')->insertOrIgnore([
                'id'             => (string) Uuid::uuid7(),
                'code'           => $currency['code'],
                'name'           => $currency['name'],
                'symbol'         => $currency['symbol'],
                'decimal_places' => $currency['decimal_places'],
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        }

        // Seed permissions into E04 authorization system
        $permissions = [
            ['slug' => 'multi_currency.create_wallet',     'display_name' => 'Create Currency Wallet',    'description' => 'Create a currency wallet'],
            ['slug' => 'multi_currency.view_balances',     'display_name' => 'View Currency Balances',    'description' => 'View own currency wallet balances'],
            ['slug' => 'multi_currency.transfer',          'display_name' => 'Transfer Between Wallets',  'description' => 'Execute same-currency transfer between own wallets'],
            ['slug' => 'multi_currency.convert',           'display_name' => 'Convert Currency',          'description' => 'Request FX quote and confirm conversion'],
            ['slug' => 'multi_currency.configure_rate',    'display_name' => 'Configure FX Rates',        'description' => 'Submit and approve FX rate changes (admin)'],
            ['slug' => 'multi_currency.manage_currencies', 'display_name' => 'Manage Currencies',         'description' => 'Add and activate currencies (admin)'],
        ];

        foreach ($permissions as $perm) {
            DB::connection('platform')->table('identity.permissions')->insertOrIgnore([
                'id'           => (string) Uuid::uuid7(),
                'slug'         => $perm['slug'],
                'display_name' => $perm['display_name'],
                'description'  => $perm['description'],
                'module'       => 'multi_currency',
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }

        // Assign customer-facing permissions to the 'customer' role
        $customerPerms = ['multi_currency.create_wallet', 'multi_currency.view_balances', 'multi_currency.transfer', 'multi_currency.convert'];
        foreach ($customerPerms as $slug) {
            $permId = DB::connection('platform')->table('identity.permissions')->where('slug', $slug)->value('id');
            $roleId = DB::connection('platform')->table('identity.roles')->where('slug', 'customer')->value('id');
            if ($permId && $roleId) {
                DB::connection('platform')->table('identity.role_permissions')->insertOrIgnore([
                    'id'            => (string) Uuid::uuid7(),
                    'role_id'       => $roleId,
                    'permission_id' => $permId,
                ]);
            }
        }

        // Assign admin permissions to the 'admin' role
        $adminPerms = ['multi_currency.configure_rate', 'multi_currency.manage_currencies'];
        foreach ($adminPerms as $slug) {
            $permId = DB::connection('platform')->table('identity.permissions')->where('slug', $slug)->value('id');
            $roleId = DB::connection('platform')->table('identity.roles')->where('slug', 'admin')->value('id');
            if ($permId && $roleId) {
                DB::connection('platform')->table('identity.role_permissions')->insertOrIgnore([
                    'id'            => (string) Uuid::uuid7(),
                    'role_id'       => $roleId,
                    'permission_id' => $permId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $slugs = [
            'multi_currency.create_wallet',
            'multi_currency.view_balances',
            'multi_currency.transfer',
            'multi_currency.convert',
            'multi_currency.configure_rate',
            'multi_currency.manage_currencies',
        ];
        DB::connection('platform')->table('identity.permissions')->whereIn('slug', $slugs)->delete();
        $codes = ['BWP', 'USD', 'ZAR', 'EUR', 'GBP', 'ZMW'];
        DB::connection('platform')->table('multi_currency.currencies')->whereIn('code', $codes)->delete();
    }
};
