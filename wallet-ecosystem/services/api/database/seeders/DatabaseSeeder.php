<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call([
            IdentityAdminSeeder::class,
            CustomerAgentSeeder::class,
            DevCustomerSeeder::class,
            DevAgentSeeder::class,
            WatchlistEntrySeeder::class,
            ReportPermissionSeeder::class,
            ReportDefinitionSeeder::class,
            ReportConfigSeeder::class,
            E26SecurityPermissionsSeeder::class,
            OpsPermissionSeeder::class,
            AdminAppPermissionsSeeder::class,
            CommunityFuneralPermissionSeeder::class,
        ]);
    }
}
