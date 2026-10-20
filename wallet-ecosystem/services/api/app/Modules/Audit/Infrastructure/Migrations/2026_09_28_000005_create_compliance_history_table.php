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
        Schema::connection('platform')->create('audit.compliance_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->timestampTz('changed_at');
            $table->string('field_changed', 32);
            $table->jsonb('before_value')->nullable();
            $table->jsonb('after_value');
            $table->string('source_system', 16);
            $table->uuid('triggering_audit_event_id')->nullable();
            $table->boolean('out_of_order')->default(false);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['user_id', 'changed_at']);
            $table->index('triggering_audit_event_id');
        });

        // Append-only trigger (same as audit_events)
        DB::connection('platform')->statement(<<<'SQL'
            CREATE TRIGGER prevent_compliance_history_mutation
            BEFORE UPDATE OR DELETE ON audit.compliance_history
            FOR EACH ROW EXECUTE FUNCTION audit.prevent_audit_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::connection('platform')->statement(
            'DROP TRIGGER IF EXISTS prevent_compliance_history_mutation ON audit.compliance_history'
        );
        Schema::connection('platform')->dropIfExists('audit.compliance_history');
    }
};
