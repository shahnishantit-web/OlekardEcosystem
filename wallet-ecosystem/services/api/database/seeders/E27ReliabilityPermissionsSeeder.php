<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class E27ReliabilityPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['slug' => 'operations.dead_letter.manage', 'description' => 'View, replay, and dismiss dead-letter items'],
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
