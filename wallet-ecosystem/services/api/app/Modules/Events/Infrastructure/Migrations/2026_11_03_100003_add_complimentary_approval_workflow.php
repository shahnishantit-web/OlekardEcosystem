<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        $schema = Schema::connection('community_services');
        if (! $schema->hasTable('community_services.event_complimentary_approvals')) {
            $schema->create('community_services.event_complimentary_approvals', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('organisation_id')->index();
                $table->uuid('event_id')->index();
                $table->uuid('category_id')->index();
                $table->unsignedInteger('approval_version');
                $table->unsignedInteger('requested_event_version');
                $table->uuid('requested_by_actor_id');
                $table->uuid('approved_by_actor_id')->nullable();
                $table->uuid('approval_assignment_id')->nullable();
                $table->uuid('revoked_by_assignment_id')->nullable();
                $table->unsignedInteger('approved_quota');
                $table->timestampTz('valid_from');
                $table->timestampTz('expires_at');
                $table->string('status', 16)->default('requested');
                $table->uuid('provenance_audit_id')->nullable();
                $table->timestampTz('approved_at')->nullable();
                $table->uuid('revoked_by_actor_id')->nullable();
                $table->timestampTz('revoked_at')->nullable();
                $table->string('revocation_reason', 80)->nullable();
                $table->string('idempotency_key', 128);
                $table->char('request_hash', 64);
                $table->timestampsTz();
                $table->unique(['organisation_id', 'event_id', 'requested_by_actor_id', 'idempotency_key'], 'event_comp_approval_idem_uq');
                $table->unique(['organisation_id', 'event_id', 'category_id', 'approval_version'], 'event_comp_approval_version_uq');
                $table->foreign('event_id')->references('id')->on('community_services.event_drafts')->restrictOnDelete();
                $table->foreign('category_id')->references('id')->on('community_services.event_draft_categories')->restrictOnDelete();
                $table->index(['organisation_id', 'event_id', 'category_id', 'status', 'revoked_at'], 'event_comp_approval_lookup_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('community_services')->dropIfExists('community_services.event_complimentary_approvals');
    }
};
