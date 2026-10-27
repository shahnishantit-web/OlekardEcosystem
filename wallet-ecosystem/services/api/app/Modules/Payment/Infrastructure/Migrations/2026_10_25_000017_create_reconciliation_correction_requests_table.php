<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'payment';

    public function up(): void
    {
        Schema::connection('payment')->create('reconciliation_correction_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('exception_id');
            $table->string('idempotency_key', 200);
            $table->string('correction_type', 50);
            $table->text('description');
            $table->text('evidence_note');
            $table->string('evidence_reference', 500)->nullable();
            $table->string('debit_account', 100)->nullable();
            $table->string('credit_account', 100)->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('status', 30)->default('pending_approval');
            $table->uuid('requested_by');
            $table->uuid('reviewed_by')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('ledger_entry_id', 200)->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();
        });

        DB::connection('payment')->statement(
            'CREATE UNIQUE INDEX recon_corrections_idempotency_key_idx
             ON payment.reconciliation_correction_requests (idempotency_key)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX recon_corrections_exception_id_idx
             ON payment.reconciliation_correction_requests (exception_id)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX recon_corrections_status_idx
             ON payment.reconciliation_correction_requests (status)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX recon_corrections_requested_by_idx
             ON payment.reconciliation_correction_requests (requested_by)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX recon_corrections_reviewed_by_idx
             ON payment.reconciliation_correction_requests (reviewed_by)'
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.reconciliation_correction_requests
             ADD CONSTRAINT recon_corrections_exception_fk
             FOREIGN KEY (exception_id) REFERENCES payment.reconciliation_exceptions (id)"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.reconciliation_correction_requests
             ADD CONSTRAINT recon_corrections_status_check
             CHECK (status IN ('pending_approval','approved','rejected','posted','posting_failed'))"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.reconciliation_correction_requests
             ADD CONSTRAINT recon_corrections_amount_min_check
             CHECK (amount_minor >= 1)"
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.recon_corrections_idempotency_key_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.recon_corrections_exception_id_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.recon_corrections_status_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.recon_corrections_requested_by_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.recon_corrections_reviewed_by_idx'
        );
        Schema::connection('payment')->dropIfExists('reconciliation_correction_requests');
    }
};
