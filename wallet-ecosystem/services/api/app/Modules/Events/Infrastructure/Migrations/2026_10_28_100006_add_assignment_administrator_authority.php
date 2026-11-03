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
        $schema->create('community_services.event_assignment_administrators', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organisation_id');
            $table->uuid('actor_id');
            $table->string('authority_type', 24);
            $table->string('grant_source', 20);
            $table->uuid('granted_by_actor_id');
            $table->uuid('approver_one_actor_id')->nullable();
            $table->uuid('approver_two_actor_id')->nullable();
            $table->string('approval_reference', 255);
            $table->timestampTz('granted_at');
            $table->uuid('revoked_by_actor_id')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['organisation_id', 'authority_type', 'revoked_at'], 'event_assignment_admin_scope_idx');
            $table->index(['actor_id', 'authority_type', 'revoked_at'], 'event_assignment_admin_actor_idx');
        });
        DB::connection('community_services')->statement(
            "ALTER TABLE community_services.event_assignment_administrators
             ADD CONSTRAINT event_assignment_admin_type_check CHECK (authority_type IN ('organisation','finance'))"
        );
        DB::connection('community_services')->statement(
            "ALTER TABLE community_services.event_assignment_administrators
             ADD CONSTRAINT event_assignment_admin_source_check CHECK (grant_source IN ('bootstrap','delegated'))"
        );
        DB::connection('community_services')->statement(
            'CREATE UNIQUE INDEX event_assignment_admin_active_unique
             ON community_services.event_assignment_administrators (organisation_id, actor_id, authority_type)
             WHERE revoked_at IS NULL'
        );
        DB::connection('community_services')->statement(
            'CREATE UNIQUE INDEX event_assignment_admin_approval_reference_unique
             ON community_services.event_assignment_administrators (approval_reference)'
        );

        $schema->create('community_services.event_assignment_administrator_audit', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('administrator_record_id')->nullable();
            $table->string('action', 32);
            $table->uuid('actor_id');
            $table->uuid('target_actor_id');
            $table->uuid('organisation_id');
            $table->string('authority_type', 24);
            $table->uuid('approver_one_actor_id')->nullable();
            $table->uuid('approver_two_actor_id')->nullable();
            $table->string('approval_reference', 255);
            $table->string('reason_code', 80)->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestampTz('created_at');
            $table->index(['organisation_id', 'created_at'], 'event_assignment_admin_audit_scope_idx');
        });
        $schema->create('community_services.event_assignment_access_audit', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('actor_id');
            $table->uuid('organisation_id');
            $table->uuid('issuer_actor_id');
            $table->string('authority_type', 24);
            $table->uuid('correlation_id')->nullable();
            $table->timestampTz('created_at');
            $table->index(['organisation_id', 'created_at'], 'event_assignment_access_audit_scope_idx');
        });
        $schema->table('community_services.event_authority_assignment_audit', function (Blueprint $table): void {
            $table->uuid('correlation_id')->nullable();
        });

        foreach (EventPermissions::management() as $slug) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id' => (string) Uuid::uuid7(), 'slug' => $slug,
                'display_name' => ucwords(str_replace('.', ' ', $slug)), 'module' => 'events',
                'is_system_permission' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('community_services')->table('community_services.event_authority_assignment_audit', function (Blueprint $table): void {
            $table->dropColumn('correlation_id');
        });
        Schema::connection('community_services')->dropIfExists('community_services.event_assignment_administrator_audit');
        Schema::connection('community_services')->dropIfExists('community_services.event_assignment_access_audit');
        DB::connection('community_services')->statement('DROP INDEX IF EXISTS community_services.event_assignment_admin_active_unique');
        DB::connection('community_services')->statement('DROP INDEX IF EXISTS community_services.event_assignment_admin_approval_reference_unique');
        Schema::connection('community_services')->dropIfExists('community_services.event_assignment_administrators');
        foreach (EventPermissions::management() as $slug) {
            $permissionId = DB::connection('identity')->table('identity.permissions')->where('slug', $slug)->value('id');
            if ($permissionId !== null && ! DB::connection('identity')->table('identity.role_permissions')->where('permission_id', $permissionId)->exists()) {
                DB::connection('identity')->table('identity.permissions')->where('id', $permissionId)->delete();
            }
        }
    }
};
