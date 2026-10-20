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
        foreach (['security.config.read' => 'View security policies', 'security.config.write' => 'Propose security policies', 'security.events.read' => 'View security events', 'security.events.export' => 'Export security events'] as $slug => $label) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore(['id' => (string) Uuid::uuid7(), 'slug' => $slug, 'display_name' => $label, 'module' => 'security', 'is_system_permission' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    { /* Definitions may be assigned to existing roles; preserve grants on rollback. */
    }
};
