<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Platform\Domain\Permissions\PlatformPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class E28ConfigurationPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            [
                'slug'        => PlatformPermissions::CONFIGURATION_READ,
                'description' => 'Read configuration keys, current values, and version history',
            ],
            [
                'slug'        => PlatformPermissions::CONFIGURATION_WRITE,
                'description' => 'Submit configuration change requests (maker role)',
            ],
            [
                'slug'        => PlatformPermissions::CONFIGURATION_APPROVE,
                'description' => 'Approve or reject pending configuration change requests (checker role)',
            ],
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
