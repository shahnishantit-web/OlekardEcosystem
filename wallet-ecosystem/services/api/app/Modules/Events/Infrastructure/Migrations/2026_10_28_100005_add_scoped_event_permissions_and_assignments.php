<?php

declare(strict_types=1);

use App\Modules\Events\Domain\Permissions\EventPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        $schema = Schema::connection('community_services');
        $schema->create('community_services.event_authority_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope_type', 20);
            $table->uuid('scope_id');
            $table->uuid('organisation_id');
            $table->uuid('event_id')->nullable();
            $table->uuid('gate_id')->nullable();
            $table->uuid('actor_id');
            $table->string('permission_slug', 100);
            $table->uuid('granted_by_actor_id');
            $table->timestampTz('granted_at');
            $table->uuid('revoked_by_actor_id')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['organisation_id', 'event_id', 'gate_id'], 'event_auth_assignment_resource_idx');
            $table->index(['actor_id', 'permission_slug', 'revoked_at'], 'event_auth_assignment_actor_idx');
        });
        DB::connection('community_services')->statement(
            "ALTER TABLE community_services.event_authority_assignments
             ADD CONSTRAINT event_auth_assignment_scope_check CHECK (
                (scope_type = 'organisation' AND event_id IS NULL AND gate_id IS NULL AND scope_id = organisation_id)
                OR (scope_type = 'event' AND event_id IS NOT NULL AND gate_id IS NULL AND scope_id = event_id)
                OR (scope_type = 'gate' AND event_id IS NOT NULL AND gate_id IS NOT NULL AND scope_id = gate_id)
             )"
        );
        DB::connection('community_services')->statement(
            'CREATE UNIQUE INDEX event_auth_assignment_active_unique ON community_services.event_authority_assignments
             (scope_type, scope_id, actor_id, permission_slug) WHERE revoked_at IS NULL'
        );

        $schema->create('community_services.event_authority_assignment_audit', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('assignment_id');
            $table->string('action', 20);
            $table->uuid('actor_id');
            $table->uuid('target_actor_id');
            $table->string('scope_type', 20);
            $table->uuid('scope_id');
            $table->string('permission_slug', 100);
            $table->string('reason_code', 80)->nullable();
            $table->timestampTz('created_at');
            $table->index(['assignment_id', 'created_at'], 'event_auth_assignment_audit_idx');
        });

        foreach (EventPermissions::all() as $slug) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id' => (string) Uuid::uuid7(),
                'slug' => $slug,
                'display_name' => ucwords(str_replace('.', ' ', $slug)),
                'module' => 'events',
                'is_system_permission' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('community_services')->dropIfExists('community_services.event_authority_assignment_audit');
        DB::connection('community_services')->statement(
            'DROP INDEX IF EXISTS community_services.event_auth_assignment_active_unique'
        );
        Schema::connection('community_services')->dropIfExists('community_services.event_authority_assignments');

        foreach (EventPermissions::all() as $slug) {
            $permissionId = DB::connection('identity')->table('identity.permissions')->where('slug', $slug)->value('id');
            if ($permissionId !== null && ! DB::connection('identity')->table('identity.role_permissions')->where('permission_id', $permissionId)->exists()) {
                DB::connection('identity')->table('identity.permissions')->where('id', $permissionId)->delete();
            }
        }
    }
};
