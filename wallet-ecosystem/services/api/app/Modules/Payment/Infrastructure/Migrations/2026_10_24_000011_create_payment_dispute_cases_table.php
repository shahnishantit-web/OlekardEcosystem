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
        Schema::connection('payment')->create('payment_dispute_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payment_attempt_id')->index();
            $table->string('provider_code', 64);
            $table->string('provider_dispute_reference', 255);
            $table->string('case_type', 20);
            $table->string('status', 30)->default('received');
            $table->bigInteger('disputed_amount_minor');
            $table->char('currency_code', 3);
            $table->string('reason_code', 64)->nullable();
            $table->timestampTz('response_deadline')->nullable();
            $table->bigInteger('net_exposure_minor')->default(0);
            $table->bigInteger('prior_refunds_minor')->default(0);
            $table->string('exposure_type', 30)->nullable();
            $table->bigInteger('hold_amount_minor')->default(0);
            $table->string('entitlement_action', 30)->default('pending');
            $table->string('liability_exception_type', 64)->nullable();
            $table->boolean('requires_finance_review')->default(false);
            $table->boolean('finance_reviewed')->default(false);
            $table->uuid('finance_reviewed_by')->nullable();
            $table->timestampTz('finance_reviewed_at')->nullable();
            $table->boolean('evidence_submitted')->default(false);
            $table->string('accounting_status', 40)->default('pending');
            $table->string('chargeback_ledger_journal_id', 128)->nullable();
            $table->string('fee_ledger_journal_id', 128)->nullable();
            $table->string('provider_decision_reference', 255)->nullable();
            $table->string('correlation_id', 128);
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
        });

        // Deduplication anchor — one case per (provider_code, provider_dispute_reference)
        DB::connection('payment')->statement(
            'CREATE UNIQUE INDEX payment_dispute_cases_provider_ref_idx
             ON payment.payment_dispute_cases (provider_code, provider_dispute_reference)'
        );

        // Efficient filtering for admin list API
        DB::connection('payment')->statement(
            'CREATE INDEX payment_dispute_cases_status_deadline_idx
             ON payment.payment_dispute_cases (status, response_deadline)'
        );

        // Partial index for open-case queries
        DB::connection('payment')->statement(
            "CREATE INDEX payment_dispute_cases_open_idx
             ON payment.payment_dispute_cases (status)
             WHERE status NOT IN ('won','lost','closed_reversed')"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_dispute_cases
             ADD CONSTRAINT payment_dispute_cases_status_check
             CHECK (status IN ('received','under_review','won','lost','closed_reversed','investigation_required'))"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_dispute_cases
             ADD CONSTRAINT payment_dispute_cases_case_type_check
             CHECK (case_type IN ('reversal','dispute','chargeback'))"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_dispute_cases
             ADD CONSTRAINT payment_dispute_cases_entitlement_action_check
             CHECK (entitlement_action IN ('pending','blocked','consumed','post_draw_liability','no_handler','coordination_failed'))"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_dispute_cases
             ADD CONSTRAINT payment_dispute_cases_accounting_status_check
             CHECK (accounting_status IN ('pending','correcting_journal_posted','fee_posted','hold_released','receivable_recorded','pending_chargeback_fee_policy','no_journal_required'))"
        );

        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_dispute_cases
             ADD CONSTRAINT payment_dispute_cases_attempt_fk
             FOREIGN KEY (payment_attempt_id) REFERENCES payment.payment_attempts (id)'
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payment_dispute_cases_provider_ref_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payment_dispute_cases_status_deadline_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payment_dispute_cases_open_idx'
        );

        Schema::connection('payment')->dropIfExists('payment_dispute_cases');
    }
};
