<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'support';

    public function up(): void
    {
        Schema::connection('support')->create('support.disputes', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('dispute_number', 20)->unique()->notNull();
            $table->uuid('transaction_id')->notNull();
            $table->uuid('ticket_id')->nullable();
            $table->uuid('reporter_id')->notNull();
            $table->string('reporter_type', 20)->notNull();
            $table->string('reason_code', 50)->nullable();
            $table->text('reason_description')->nullable();
            $table->text('description')->notNull();
            $table->string('status', 30)->notNull()->default('raised');
            $table->boolean('escalated')->notNull()->default(false);
            $table->timestampTz('escalated_at')->nullable();
            $table->text('escalation_reason')->nullable();
            $table->uuid('investigator_id')->nullable();
            $table->timestampTz('investigation_started_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('second_approver_id')->nullable();
            $table->timestampTz('second_approved_at')->nullable();
            $table->uuid('refund_confirmed_by')->nullable();
            $table->timestampTz('refund_confirmed_at')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->string('rejection_reason', 100)->nullable();
            $table->text('rejection_notes')->nullable();
            $table->text('resolution_note')->nullable();
            $table->bigInteger('refunded_amount_minor')->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->text('partial_refund_justification')->nullable();
            $table->uuid('refund_ledger_entry_id')->nullable();
            $table->string('refund_idempotency_key', 255)->unique()->nullable();
            $table->timestampTz('dispute_refunded_at')->nullable();
            $table->uuid('refund_initiator_id')->nullable();
            $table->boolean('refund_pending_confirmation')->notNull()->default(false);
            $table->string('idempotency_key', 255)->unique()->nullable();
            $table->string('correlation_id', 100)->notNull();
            $table->timestampsTz();
        });

        // Partial unique index: one active dispute per transaction
        DB::connection('support')->statement(
            "CREATE UNIQUE INDEX idx_disputes_transaction_id_active ON support.disputes (transaction_id)
             WHERE status NOT IN ('rejected', 'refunded')"
        );

        DB::connection('support')->statement(
            'CREATE INDEX idx_disputes_reporter ON support.disputes (reporter_id, reporter_type)'
        );
        DB::connection('support')->statement(
            'CREATE INDEX idx_disputes_status ON support.disputes (status)'
        );
        DB::connection('support')->statement(
            'CREATE INDEX idx_disputes_escalated ON support.disputes (escalated) WHERE escalated = true'
        );
    }

    public function down(): void
    {
        Schema::connection('support')->dropIfExists('support.disputes');
    }
};
