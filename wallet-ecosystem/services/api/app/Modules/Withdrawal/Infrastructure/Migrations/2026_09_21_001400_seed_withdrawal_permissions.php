<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        $permissions = [
            ['slug' => 'withdrawal.initiate', 'display_name' => 'Initiate Wallet Withdrawal', 'module' => 'withdrawal'],
            ['slug' => 'withdrawal.read',     'display_name' => 'View Withdrawal History',    'module' => 'withdrawal'],
            ['slug' => 'withdrawal.admin',    'display_name' => 'Administer Withdrawals',      'module' => 'withdrawal'],
        ];

        foreach ($permissions as $perm) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id'           => (string) Uuid::uuid7(),
                'slug'         => $perm['slug'],
                'display_name' => $perm['display_name'],
                'module'       => $perm['module'],
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', ['withdrawal.initiate', 'withdrawal.read', 'withdrawal.admin'])
            ->delete();
    }
};
