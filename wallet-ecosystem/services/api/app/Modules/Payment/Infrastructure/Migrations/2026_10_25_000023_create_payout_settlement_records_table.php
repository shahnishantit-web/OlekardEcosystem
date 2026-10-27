<?php

declare(strict_types=1);

// STUB — settlement confirmation blocked pending AD-10 and LC14 resolution.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'payment';

    public function up(): void
    {
        Schema::connection('payment')->create('payout_settlement_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payout_request_id');
            $table->string('provider_code', 50);
            $table->string('provider_settlement_reference', 200)->nullable();
            $table->bigInteger('settled_amount_minor');
            $table->char('currency_code', 3);
            $table->string('reconciliation_status', 30);
            $table->text('provider_evidence')->nullable();
            $table->timestampTz('settled_at')->nullable();
            $table->timestampsTz();
        });

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payout_settlement_records
             ADD CONSTRAINT psr_reconciliation_status_check
             CHECK (reconciliation_status IN ('pending','matched','discrepancy','exception_raised'))"
        );

        DB::connection('payment')->statement(
            'CREATE UNIQUE INDEX psr_payout_request_id_idx
             ON payment.payout_settlement_records (payout_request_id)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX psr_reconciliation_status_idx
             ON payment.payout_settlement_records (reconciliation_status)'
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payout_settlement_records
             ADD CONSTRAINT psr_request_fk
             FOREIGN KEY (payout_request_id) REFERENCES payment.payout_requests (id)"
        );

        // P18: Extend reconciliation_exceptions exception_type CHECK to include payout types.
        // Drop existing constraint, re-add with 18 values (16 original + 2 payout-specific).
        DB::connection('payment')->statement(
            'ALTER TABLE payment.reconciliation_exceptions
             DROP CONSTRAINT IF EXISTS recon_exceptions_type_check'
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.reconciliation_exceptions
             ADD CONSTRAINT recon_exceptions_type_check
             CHECK (exception_type IN (
                 'missing_callback',
                 'unknown_outcome',
                 'amount_mismatch',
                 'duplicate_transaction',
                 'orphan_provider',
                 'failed_refund',
                 'unmatched_ledger',
                 'contradictory_evidence',
                 'excess_capture',
                 'duplicate_capture',
                 'accounting_failure',
                 'unsupported_refund_method',
                 'definitive_refund_failure',
                 'unmatched_dispute_event',
                 'orphan_fee',
                 'partial_settlement',
                 'payout_amount_discrepancy',
                 'payout_missing_evidence'
             ))"
        );
    }

    public function down(): void
    {
        // Reverse reconciliation_exceptions type extension
        DB::connection('payment')->statement(
            'ALTER TABLE payment.reconciliation_exceptions
             DROP CONSTRAINT IF EXISTS recon_exceptions_type_check'
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.reconciliation_exceptions
             ADD CONSTRAINT recon_exceptions_type_check
             CHECK (exception_type IN (
                 'missing_callback',
                 'unknown_outcome',
                 'amount_mismatch',
                 'duplicate_transaction',
                 'orphan_provider',
                 'failed_refund',
                 'unmatched_ledger',
                 'contradictory_evidence',
                 'excess_capture',
                 'duplicate_capture',
                 'accounting_failure',
                 'unsupported_refund_method',
                 'definitive_refund_failure',
                 'unmatched_dispute_event',
                 'orphan_fee',
                 'partial_settlement'
             ))"
        );

        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.psr_payout_request_id_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.psr_reconciliation_status_idx'
        );

        Schema::connection('payment')->dropIfExists('payout_settlement_records');
    }
};
