<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'payment';

    public function up(): void
    {
        DB::connection('payment')->statement(
            'ALTER TABLE payment.reconciliation_exceptions
             ADD COLUMN IF NOT EXISTS owner varchar(100) NULL'
        );

        DB::connection('payment')->statement(
            'ALTER TABLE payment.reconciliation_exceptions
             ADD COLUMN IF NOT EXISTS aged_at timestamptz NULL'
        );

        // Repair type CHECK constraint — add two new P17 exception types
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

        // Repair status CHECK constraint — add acknowledged_external (P17 US4-4)
        DB::connection('payment')->statement(
            'ALTER TABLE payment.reconciliation_exceptions
             DROP CONSTRAINT IF EXISTS recon_exceptions_status_check'
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.reconciliation_exceptions
             ADD CONSTRAINT recon_exceptions_status_check
             CHECK (status IN (
                 'open',
                 'under_investigation',
                 'resolved',
                 'escalated',
                 'acknowledged_external'
             ))"
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'ALTER TABLE payment.reconciliation_exceptions
             DROP COLUMN IF EXISTS owner'
        );

        DB::connection('payment')->statement(
            'ALTER TABLE payment.reconciliation_exceptions
             DROP COLUMN IF EXISTS aged_at'
        );

        // Restore original type constraint (14 types)
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
                 'unmatched_dispute_event'
             ))"
        );

        // Restore original status constraint (4 statuses)
        DB::connection('payment')->statement(
            'ALTER TABLE payment.reconciliation_exceptions
             DROP CONSTRAINT IF EXISTS recon_exceptions_status_check'
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.reconciliation_exceptions
             ADD CONSTRAINT recon_exceptions_status_check
             CHECK (status IN ('open','under_investigation','resolved','escalated'))"
        );
    }
};
