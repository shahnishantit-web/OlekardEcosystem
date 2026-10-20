<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * T184: Seed profile.rate_limit.admin_per_minute configuration key.
 * Value remains TBD (is_tbd=true, config_value=null).
 * ProfileServiceProvider::boot() uses Limit::none() when the key is TBD/null (constitution P2).
 */
return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection('platform')->table('platform.configuration_values')->insertOrIgnore([
            'id'              => (string) Str::uuid(),
            'config_key'      => 'profile.rate_limit.admin_per_minute',
            'config_value'    => null,
            'value_type'      => 'number',
            'status'          => 'active',
            'version'         => 1,
            'is_tbd'          => true,
            'description'     => 'Max requests per minute per admin user to profile admin endpoints. TBD.',
            'effective_from'  => null,
            'effective_until' => null,
            'created_at'      => now()->toDateTimeString(),
            'updated_at'      => now()->toDateTimeString(),
        ]);
    }

    public function down(): void
    {
        DB::connection('platform')->table('platform.configuration_values')
            ->where('config_key', 'profile.rate_limit.admin_per_minute')
            ->where('version', 1)
            ->delete();
    }
};
