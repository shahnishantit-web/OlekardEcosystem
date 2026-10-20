<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection('platform')->table('platform.configuration_values')->insertOrIgnore([
            [
                'id'              => (string) Str::uuid(),
                'config_key'      => 'auth.permission_cache_ttl',
                'config_value'    => '300',
                'value_type'      => 'number',
                'status'          => 'active',
                'version'         => 1,
                'is_tbd'          => false,
                'description'     => 'Seconds to cache per-actor permission resolution in Redis (0 = no cache)',
                'effective_from'  => null,
                'effective_until' => null,
                'created_at'      => now()->toDateTimeString(),
                'updated_at'      => now()->toDateTimeString(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::connection('platform')->table('platform.configuration_values')
            ->where('config_key', 'auth.permission_cache_ttl')
            ->where('version', 1)
            ->delete();
    }
};
