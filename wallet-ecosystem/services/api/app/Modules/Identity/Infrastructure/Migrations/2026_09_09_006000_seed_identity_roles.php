<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection('identity')->table('identity.roles')->insertOrIgnore([
            [
                'id'             => (string) Str::orderedUuid(),
                'slug'           => 'customer',
                'display_name'   => 'Customer',
                'description'    => 'Default role for customer actors.',
                'is_system_role' => true,
                'deleted_at'     => null,
                'created_at'     => now()->toDateTimeString(),
                'updated_at'     => now()->toDateTimeString(),
            ],
            [
                'id'             => (string) Str::orderedUuid(),
                'slug'           => 'agent',
                'display_name'   => 'Agent',
                'description'    => 'Default role for agent actors.',
                'is_system_role' => true,
                'deleted_at'     => null,
                'created_at'     => now()->toDateTimeString(),
                'updated_at'     => now()->toDateTimeString(),
            ],
            [
                'id'             => (string) Str::orderedUuid(),
                'slug'           => 'admin',
                'display_name'   => 'Administrator',
                'description'    => 'Default role for admin actors.',
                'is_system_role' => true,
                'deleted_at'     => null,
                'created_at'     => now()->toDateTimeString(),
                'updated_at'     => now()->toDateTimeString(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::connection('identity')->table('identity.roles')
            ->whereIn('slug', ['customer', 'agent', 'admin'])
            ->where('is_system_role', true)
            ->delete();
    }
};
