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
            ['slug' => 'funding.initiate',           'display_name' => 'Initiate wallet funding',                    'module' => 'funding'],
            ['slug' => 'funding.read',               'display_name' => 'Read funding transactions and receipts',     'module' => 'funding'],
            ['slug' => 'funding.agent-cashin',       'display_name' => 'Perform agent cash-in',                      'module' => 'funding'],
            ['slug' => 'funding.unmatched.review',   'display_name' => 'Review and resolve unmatched bank deposits', 'module' => 'funding'],
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
            ->whereIn('slug', ['funding.initiate', 'funding.read', 'funding.agent-cashin', 'funding.unmatched.review'])
            ->delete();
    }
};
