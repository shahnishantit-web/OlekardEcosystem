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
        $db = Schema::connection('community_services');
        if (! $db->hasColumn('community_services.event_draft_audit', 'correlation_id')) {
            $db->table('community_services.event_draft_audit', static function (Blueprint $table): void {
                $table->uuid('correlation_id')->nullable();
            });
        }
        $actionLength = DB::connection('community_services')->table('information_schema.columns')
            ->where('table_schema', 'community_services')->where('table_name', 'event_draft_audit')
            ->where('column_name', 'action')->value('character_maximum_length');
        if ($actionLength !== null && (int) $actionLength < 64) {
            DB::connection('community_services')->statement(
                'ALTER TABLE community_services.event_draft_audit ALTER COLUMN action TYPE VARCHAR(64)',
            );
        }
        if (! $db->hasColumn('community_services.event_drafts', 'lifecycle_state')) {
            $db->table('community_services.event_drafts', static function (Blueprint $table): void {
                $table->string('lifecycle_state', 24)->default('active');
            });
        }

        if (! $db->hasTable('community_services.event_cancellation_requests')) {
            $db->create('community_services.event_cancellation_requests', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('event_id')->index();
                $table->uuid('organisation_id')->index();
                $table->uuid('requested_by_actor_id');
                $table->uuid('reviewed_by_actor_id')->nullable();
                $table->unsignedInteger('event_version');
                $table->string('status', 24)->default('pending');
                $table->string('reason', 1000);
                $table->string('review_reason', 1000)->nullable();
                $table->string('idempotency_key', 128);
                $table->char('request_hash', 64);
                $table->string('review_idempotency_key', 128)->nullable();
                $table->char('review_request_hash', 64)->nullable();
                $table->jsonb('review_result')->nullable();
                $table->timestampTz('requested_at');
                $table->timestampTz('reviewed_at')->nullable();
                $table->timestampTz('effective_at')->nullable();
                $table->timestampsTz();
                $table->unique(['event_id', 'requested_by_actor_id', 'idempotency_key'], 'event_cancel_request_idem_uq');
                $table->foreign('event_id')->references('id')->on('community_services.event_drafts')->restrictOnDelete();
                $table->index(['event_id', 'status', 'requested_at'], 'event_cancel_request_state_idx');
            });
        }

        if (! $db->hasTable('community_services.event_cancellation_financial_cases')) {
            $db->create('community_services.event_cancellation_financial_cases', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('cancellation_request_id')->index();
                $table->uuid('event_id')->index();
                $table->uuid('organisation_id')->index();
                $table->uuid('order_id')->index();
                $table->uuid('payment_intent_id')->nullable()->index();
                $table->string('state', 32)->default('pending_reconciliation');
                $table->timestampTz('created_at');
                $table->timestampTz('updated_at');
                $table->unique(['cancellation_request_id', 'order_id'], 'event_cancel_financial_case_uq');
                $table->foreign('cancellation_request_id')->references('id')->on('community_services.event_cancellation_requests')->restrictOnDelete();
                $table->foreign('order_id')->references('id')->on('community_services.event_orders')->restrictOnDelete();
            });
        }

        if (! $db->hasColumn('community_services.event_tickets', 'cancellation_request_id')) {
            $db->table('community_services.event_tickets', static function (Blueprint $table): void {
                $table->uuid('cancellation_request_id')->nullable();
                $table->foreign('cancellation_request_id', 'event_ticket_cancellation_request_fk')
                    ->references('id')->on('community_services.event_cancellation_requests')->restrictOnDelete();
                $table->index('cancellation_request_id', 'event_ticket_cancellation_request_idx');
            });
        }
    }

    public function down(): void
    {
        $db = Schema::connection('community_services');
        $db->dropIfExists('community_services.event_cancellation_financial_cases');
        if ($db->hasColumn('community_services.event_tickets', 'cancellation_request_id')) {
            $db->table('community_services.event_tickets', static function (Blueprint $table): void {
                $table->dropForeign('event_ticket_cancellation_request_fk');
                $table->dropIndex('event_ticket_cancellation_request_idx');
                $table->dropColumn('cancellation_request_id');
            });
        }
        $db->dropIfExists('community_services.event_cancellation_requests');
        if ($db->hasColumn('community_services.event_draft_audit', 'correlation_id')) {
            $db->table('community_services.event_draft_audit', static fn (Blueprint $table) => $table->dropColumn('correlation_id'));
        }
        if ($db->hasColumn('community_services.event_drafts', 'lifecycle_state')) {
            $db->table('community_services.event_drafts', static fn (Blueprint $table) => $table->dropColumn('lifecycle_state'));
        }
    }
};
