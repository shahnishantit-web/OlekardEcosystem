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
            ['slug' => 'service.manage', 'display_name' => 'Manage service catalog (admin CRUD + force-outcome)', 'module' => 'service'],
            ['slug' => 'service.list',   'display_name' => 'View active service catalog and payment status',      'module' => 'service'],
            ['slug' => 'service.pay',    'display_name' => 'Initiate service payments from own wallet',           'module' => 'service'],
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
            ->whereIn('slug', ['service.manage', 'service.list', 'service.pay'])
            ->delete();
    }
};
