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
        foreach ([
            'currency_precision_snapshot' => fn (Blueprint $table) => $table->unsignedSmallInteger('currency_precision_snapshot')->nullable(),
            'currency_definition_updated_at' => fn (Blueprint $table) => $table->timestampTz('currency_definition_updated_at')->nullable(),
            'currency_assignment_id' => fn (Blueprint $table) => $table->uuid('currency_assignment_id')->nullable(),
            'currency_locked_at' => fn (Blueprint $table) => $table->timestampTz('currency_locked_at')->nullable(),
            'currency_lock_reason' => fn (Blueprint $table) => $table->string('currency_lock_reason', 32)->nullable(),
        ] as $column => $definition) {
            if (! $schema->hasColumn('community_services.event_drafts', $column)) {
                $schema->table('community_services.event_drafts', $definition);
            }
        }
        if (! $schema->hasTable('community_services.event_currency_assignments')) {
            $schema->create('community_services.event_currency_assignments', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('event_id')->index();
                $table->uuid('organisation_id')->index();
                $table->char('currency_code', 3);
                $table->unsignedSmallInteger('precision_snapshot');
                $table->timestampTz('currency_definition_updated_at');
                $table->uuid('assigned_by_actor_id');
                $table->string('approval_reference', 160);
                $table->string('policy_version', 80);
                $table->timestampTz('assigned_at');
                $table->timestampTz('superseded_at')->nullable();
                $table->timestampsTz();
                $table->foreign('event_id')->references('id')->on('community_services.event_drafts')->restrictOnDelete();
                $table->unique(['event_id', 'id'], 'event_currency_assignment_event_id_uq');
            });
            DB::connection('community_services')->statement(
                'CREATE UNIQUE INDEX event_currency_assignment_active_uq ON community_services.event_currency_assignments(event_id) WHERE superseded_at IS NULL',
            );
        }
        if (! $schema->hasTable('community_services.event_currency_review_issues')) {
            $schema->create('community_services.event_currency_review_issues', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('event_id')->index();
                $table->string('reason_code', 48);
                $table->timestampTz('detected_at');
                $table->timestampTz('resolved_at')->nullable();
                $table->timestampsTz();
                $table->foreign('event_id')->references('id')->on('community_services.event_drafts')->restrictOnDelete();
            });
            DB::connection('community_services')->statement(
                'CREATE UNIQUE INDEX event_currency_review_issue_open_uq ON community_services.event_currency_review_issues(event_id, reason_code) WHERE resolved_at IS NULL',
            );
        }
    }

    public function down(): void
    {
        DB::connection('community_services')->statement('DROP INDEX IF EXISTS community_services.event_currency_review_issue_open_uq');
        DB::connection('community_services')->statement('DROP INDEX IF EXISTS community_services.event_currency_assignment_active_uq');
        Schema::connection('community_services')->dropIfExists('community_services.event_currency_review_issues');
        Schema::connection('community_services')->dropIfExists('community_services.event_currency_assignments');
        Schema::connection('community_services')->table('community_services.event_drafts', static function (Blueprint $table): void {
            $table->dropColumn(['currency_precision_snapshot', 'currency_definition_updated_at', 'currency_assignment_id', 'currency_locked_at', 'currency_lock_reason']);
        });
    }
};
