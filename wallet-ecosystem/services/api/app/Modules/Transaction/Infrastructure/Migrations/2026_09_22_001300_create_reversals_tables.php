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
        Schema::connection('platform')->create('transaction.reversals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id');
            $table->string('status', 30)->default('pending_approval');
            $table->uuid('requested_by');
            $table->timestampTz('requested_at');
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->string('rejected_reason', 1000)->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->string('compensating_journal_id', 100)->nullable();
            $table->string('idempotency_key', 255);
            $table->uuid('correlation_id')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE transaction.reversals ADD CONSTRAINT rev_transaction_fk FOREIGN KEY (transaction_id) REFERENCES transaction.transaction_projections(id)");
        DB::statement("ALTER TABLE transaction.reversals ADD CONSTRAINT rev_status_check CHECK (status IN ('pending_approval','approved','rejected','executed','failed'))");
        DB::statement("CREATE UNIQUE INDEX rev_idempotency_idx ON transaction.reversals (idempotency_key)");
        DB::statement("CREATE UNIQUE INDEX rev_active_per_transaction_idx ON transaction.reversals (transaction_id) WHERE status IN ('pending_approval','approved')");
        DB::statement("CREATE INDEX rev_transaction_idx ON transaction.reversals (transaction_id)");

        Schema::connection('platform')->create('transaction.reversal_audit_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('reversal_id');
            $table->uuid('transaction_id');
            $table->string('action', 100);
            $table->uuid('actor_id')->nullable();
            $table->string('actor_type', 50)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('occurred_at');
        });

        DB::statement("ALTER TABLE transaction.reversal_audit_log ADD CONSTRAINT ral_reversal_fk FOREIGN KEY (reversal_id) REFERENCES transaction.reversals(id)");
        DB::statement("CREATE INDEX ral_reversal_occurred_idx ON transaction.reversal_audit_log (reversal_id, occurred_at ASC)");
        DB::statement("CREATE INDEX ral_transaction_occurred_idx ON transaction.reversal_audit_log (transaction_id, occurred_at ASC)");
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('transaction.reversal_audit_log');
        Schema::connection('platform')->dropIfExists('transaction.reversals');
    }
};
