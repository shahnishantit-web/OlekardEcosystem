<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        $schema = Schema::connection('community_services');
        $schema->create('community_services.event_gates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organisation_id');
            $table->uuid('event_id');
            $table->string('name', 120);
            $table->string('status', 20)->default('active');
            $table->uuid('created_by_actor_id');
            $table->timestampsTz();
            $table->unique(['event_id', 'name'], 'event_gate_event_name_unique');
            $table->unique(['id', 'event_id', 'organisation_id'], 'event_gate_scope_unique');
            $table->index(['event_id', 'status'], 'event_gate_event_status_idx');
            $table->foreign('event_id')->references('id')->on('community_services.event_drafts')->restrictOnDelete();
        });
        DB::connection('community_services')->statement(
            "ALTER TABLE community_services.event_gates
             ADD CONSTRAINT event_gate_status_check CHECK (status IN ('active','retired'))",
        );
        $schema->table('community_services.event_authority_assignments', function (Blueprint $table): void {
            $table->foreign(['gate_id', 'event_id', 'organisation_id'], 'event_assignment_gate_scope_fk')
                ->references(['id', 'event_id', 'organisation_id'])->on('community_services.event_gates')->restrictOnDelete();
        });
        $schema->create('community_services.event_gate_audit', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gate_id');
            $table->uuid('event_id');
            $table->uuid('actor_id');
            $table->string('action', 32);
            $table->uuid('correlation_id')->nullable();
            $table->timestampTz('created_at');
            $table->index(['gate_id', 'created_at'], 'event_gate_audit_gate_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('community_services')->table('community_services.event_authority_assignments', function (Blueprint $table): void {
            $table->dropForeign('event_assignment_gate_scope_fk');
        });
        Schema::connection('community_services')->dropIfExists('community_services.event_gate_audit');
        Schema::connection('community_services')->dropIfExists('community_services.event_gates');
    }
};
