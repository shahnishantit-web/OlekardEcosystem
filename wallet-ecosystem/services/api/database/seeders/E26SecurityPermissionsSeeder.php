<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class E26SecurityPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['slug' => 'security.findings.read',  'description' => 'View vulnerability findings and security dashboard'],
            ['slug' => 'security.findings.write', 'description' => 'Create, suppress, and resolve vulnerability findings'],
        ];

        foreach ($permissions as $permission) {
            DB::connection('identity')->table('identity.permissions')->upsert(
                $permission,
                ['slug'],
                ['description']
            );
        }
    }
}
