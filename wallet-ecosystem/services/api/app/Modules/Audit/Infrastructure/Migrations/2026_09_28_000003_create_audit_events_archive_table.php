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
        Schema::connection('platform')->create('audit.audit_events_archive', function (Blueprint $table): void {
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
            $table->timestampTz('created_at');
            $table->timestampTz('archived_at')->default(DB::raw('now()'));
        });

        DB::connection('platform')->statement(
            'CREATE INDEX audit_events_archive_occurred_idx ON audit.audit_events_archive (occurred_at DESC)'
        );
        DB::connection('platform')->statement(
            'CREATE INDEX audit_events_archive_archived_idx ON audit.audit_events_archive (archived_at DESC)'
        );

        // Same immutability triggers — archive is also append-only
        DB::connection('platform')->statement(
            'CREATE TRIGGER audit_events_archive_no_update
             BEFORE UPDATE ON audit.audit_events_archive
             FOR EACH ROW EXECUTE FUNCTION audit.prevent_audit_mutation()'
        );
        DB::connection('platform')->statement(
            'CREATE TRIGGER audit_events_archive_no_delete
             BEFORE DELETE ON audit.audit_events_archive
             FOR EACH ROW EXECUTE FUNCTION audit.prevent_audit_mutation()'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('audit.audit_events_archive');
    }
};
