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
        Schema::connection('payment')->table('payment_refunds', function (Blueprint $table): void {
            $table->string('provider_submitted_ref', 500)->nullable()->after('provider_refund_reference');
            $table->timestampTz('submitted_at')->nullable()->after('confirmed_at');
            $table->timestampTz('pending_at')->nullable()->after('submitted_at');
            $table->timestampTz('unknown_at')->nullable()->after('pending_at');
            $table->string('refund_accounting_status', 64)->nullable()->after('failure_reason');
            $table->uuid('refund_ledger_journal_id')->nullable()->after('refund_accounting_status');
            $table->smallInteger('query_attempts')->default(0)->after('refund_ledger_journal_id');
        });

        // Drop and replace the status CHECK constraint to include new P15 states.
        // Also adds 'rejected' which was in the enum but missing from the original constraint.
        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_refunds DROP CONSTRAINT IF EXISTS payment_refunds_status_check'
        );
        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_refunds ADD CONSTRAINT payment_refunds_status_check
             CHECK (status IN (
               'requested',
               'pending_approval',
               'processing',
               'submitted',
               'pending',
               'unknown',
               'confirmed',
               'definitive_failure',
               'succeeded',
               'failed',
               'cancelled',
               'rejected'
             ))"
        );

        // Partial index for efficient in-flight reservation queries (FR-001)
        DB::connection('payment')->statement(
            "CREATE INDEX payment_refunds_attempt_inflight_idx
             ON payment.payment_refunds (payment_attempt_id, status)
             WHERE status NOT IN ('confirmed','succeeded','failed','cancelled','rejected','definitive_failure')"
        );

        // Unique index for bank callback matching via provider_submitted_ref (FR-006)
        DB::connection('payment')->statement(
            'CREATE UNIQUE INDEX payment_refunds_provider_submitted_ref_idx
             ON payment.payment_refunds (provider_submitted_ref)
             WHERE provider_submitted_ref IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payment_refunds_provider_submitted_ref_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payment_refunds_attempt_inflight_idx'
        );

        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_refunds DROP CONSTRAINT IF EXISTS payment_refunds_status_check'
        );
        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_refunds ADD CONSTRAINT payment_refunds_status_check
             CHECK (status IN ('requested','pending_approval','processing','succeeded','failed','cancelled'))"
        );

        Schema::connection('payment')->table('payment_refunds', function (Blueprint $table): void {
            $table->dropColumn([
                'provider_submitted_ref',
                'submitted_at',
                'pending_at',
                'unknown_at',
                'refund_accounting_status',
                'refund_ledger_journal_id',
                'query_attempts',
            ]);
        });
    }
};
