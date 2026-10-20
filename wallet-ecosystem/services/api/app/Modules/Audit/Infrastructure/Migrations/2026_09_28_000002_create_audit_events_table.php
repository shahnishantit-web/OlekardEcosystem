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
        Schema::connection('platform')->create('audit.audit_events', function (Blueprint $table): void {
            // UUID v7 — Constitution Principle 8; same pattern as E06/E07
            $table->uuid('id')->primary();
            $table->string('event_category', 32);
            $table->string('event_type', 128);
            $table->uuid('actor_id')->nullable();
            $table->string('actor_type', 16);
            $table->string('entity_type', 64)->nullable();
            $table->uuid('entity_id')->nullable();
            $table->string('action', 255);
            $table->string('outcome', 16);
            $table->jsonb('before_state')->nullable();
            $table->jsonb('after_state')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->uuid('correlation_id');
            $table->timestampTz('occurred_at');
            $table->char('integrity_hash', 64);
            $table->timestampTz('created_at')->default(DB::raw('now()'));
        });

        // Composite indexes for common query patterns
        DB::connection('platform')->statement(
            'CREATE INDEX audit_events_category_occurred_idx ON audit.audit_events (event_category, occurred_at DESC)'
        );
        DB::connection('platform')->statement(
            'CREATE INDEX audit_events_actor_occurred_idx ON audit.audit_events (actor_id, occurred_at DESC)'
        );
        DB::connection('platform')->statement(
            'CREATE INDEX audit_events_entity_occurred_idx ON audit.audit_events (entity_type, entity_id, occurred_at DESC)'
        );
        DB::connection('platform')->statement(
            'CREATE INDEX audit_events_correlation_idx ON audit.audit_events (correlation_id)'
        );
        DB::connection('platform')->statement(
            'CREATE INDEX audit_events_occurred_idx ON audit.audit_events (occurred_at DESC)'
        );

        // DB-level immutability: BEFORE UPDATE and BEFORE DELETE triggers
        DB::connection('platform')->statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit.prevent_audit_mutation()
            RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_immutable: audit records cannot be modified or deleted'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$
        SQL);

        DB::connection('platform')->statement(
            'CREATE TRIGGER audit_events_no_update
             BEFORE UPDATE ON audit.audit_events
             FOR EACH ROW EXECUTE FUNCTION audit.prevent_audit_mutation()'
        );
        DB::connection('platform')->statement(
            'CREATE TRIGGER audit_events_no_delete
             BEFORE DELETE ON audit.audit_events
             FOR EACH ROW EXECUTE FUNCTION audit.prevent_audit_mutation()'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('audit.audit_events');
        DB::connection('platform')->statement(
            'DROP FUNCTION IF EXISTS audit.prevent_audit_mutation() CASCADE'
        );
    }
};
