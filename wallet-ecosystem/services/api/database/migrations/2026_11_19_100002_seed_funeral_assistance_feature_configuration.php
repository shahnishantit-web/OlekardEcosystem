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
        $now = now();
        $key = 'community_funeral.http_enabled';
        $description = 'Enable Funeral Assistance setup and customer obligations in non-production environments. Production remains disabled until a separate release approval.';

        DB::connection('platform')->table('platform.configurations')->insertOrIgnore([
            'id' => (string) Uuid::uuid7(),
            'config_key' => $key,
            'domain' => 'operational_policy',
            'config_type' => 'boolean',
            'is_tbd' => false,
            'description' => $description,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::connection('platform')->table('platform.configuration_values')->insertOrIgnore([
            'id' => (string) Uuid::uuid7(),
            'config_key' => $key,
            'config_value' => 'false',
            'value_type' => 'boolean',
            'status' => 'active',
            'version' => 1,
            'is_tbd' => false,
            'description' => $description,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::connection('platform')->table('platform.configuration_values')
            ->where('config_key', 'community_funeral.http_enabled')
            ->delete();
        DB::connection('platform')->table('platform.configurations')
            ->where('config_key', 'community_funeral.http_enabled')
            ->delete();
    }
};
