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
            ['slug' => 'qr.generate', 'display_name' => 'Generate QR codes for merchant payment acceptance', 'module' => 'qr'],
            ['slug' => 'qr.manage',   'display_name' => 'View and deactivate own QR codes',                  'module' => 'qr'],
            ['slug' => 'qr.pay',      'display_name' => 'Scan, quote, and confirm QR payments from own wallet', 'module' => 'qr'],
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
            ->whereIn('slug', ['qr.generate', 'qr.manage', 'qr.pay'])
            ->delete();
    }
};
