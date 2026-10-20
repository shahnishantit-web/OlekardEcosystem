<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class IntegrationSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedApiClients();
    }

    private function seedApiClients(): void
    {
        $apiKey    = 'seed' . bin2hex(random_bytes(14)); // 32-char key
        $rawSecret = bin2hex(random_bytes(32));

        DB::connection('platform')->table('integration.api_clients')->insertOrIgnore([
            'id'                    => (string) Uuid::uuid7(),
            'name'                  => 'Demo Integration Client',
            'api_key'               => $apiKey,
            'secret_hash'           => password_hash($rawSecret, PASSWORD_BCRYPT),
            'status'                => 'active',
            'permissions'           => json_encode(['webhooks.receive']),
            'rate_limit_per_minute' => 120,
            'last_used_at'          => null,
            'created_by_user_id'    => null,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        $this->command->info("IntegrationSeeder: created demo API client key={$apiKey}");
        $this->command->warn("IntegrationSeeder: raw_secret={$rawSecret} — record this; it is not stored.");
    }
}
