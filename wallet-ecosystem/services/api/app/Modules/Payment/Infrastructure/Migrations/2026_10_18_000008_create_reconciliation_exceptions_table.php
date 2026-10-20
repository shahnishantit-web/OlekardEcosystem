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
        Schema::connection('payment')->create('payment.reconciliation_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('exception_type', 64)->notNullable();
            $table->uuid('payment_intent_id')->nullable();
            $table->uuid('payment_attempt_id')->nullable();
            $table->string('provider_code', 64)->notNullable();
            $table->string('provider_reference_id', 500)->nullable();
            $table->bigInteger('detected_amount_minor')->nullable();
            $table->bigInteger('expected_amount_minor')->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->text('description')->notNullable();
            $table->string('status', 32)->default('open')->notNullable();
            $table->text('investigation_notes')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->string('resolution_reason', 500)->nullable();
            $table->timestampTz('created_at')->notNullable()->useCurrent();
            $table->timestampTz('updated_at')->notNullable()->useCurrent();
        });

        DB::connection('payment')->statement(
            "ALTER TABLE payment.reconciliation_exceptions ADD CONSTRAINT recon_exceptions_type_check
             CHECK (exception_type IN (
               'missing_callback','unknown_outcome','amount_mismatch',
               'duplicate_transaction','orphan_provider','failed_refund','unmatched_ledger'
             ))"
        );
        DB::connection('payment')->statement(
            "ALTER TABLE payment.reconciliation_exceptions ADD CONSTRAINT recon_exceptions_status_check
             CHECK (status IN ('open','under_investigation','resolved','escalated'))"
        );

        DB::connection('payment')->statement(
            'CREATE INDEX recon_exceptions_status_created_idx
             ON payment.reconciliation_exceptions (status, created_at)'
        );
        DB::connection('payment')->statement(
            'CREATE INDEX recon_exceptions_provider_ref_idx
             ON payment.reconciliation_exceptions (provider_code, provider_reference_id)'
        );
        DB::connection('payment')->statement(
            'CREATE INDEX recon_exceptions_intent_id_idx
             ON payment.reconciliation_exceptions (payment_intent_id)'
        );
        DB::connection('payment')->statement(
            'CREATE INDEX recon_exceptions_attempt_id_idx
             ON payment.reconciliation_exceptions (payment_attempt_id)'
        );

        // Documentation gap tracking table
        Schema::connection('payment')->create('payment.bank_provider_documentation_gaps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider_code', 64)->unique()->notNullable();
            $table->string('bank_display_name', 255)->notNullable();
            $table->jsonb('required_items')->notNullable();
            $table->jsonb('received_items')->default('[]')->notNullable();
            $table->string('status', 32)->default('blocked')->notNullable();
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->notNullable()->useCurrent();
            $table->timestampTz('updated_at')->notNullable()->useCurrent();
        });

        DB::connection('payment')->statement(
            "ALTER TABLE payment.bank_provider_documentation_gaps ADD CONSTRAINT bank_doc_gaps_status_check
             CHECK (status IN ('blocked','partial','complete'))"
        );
    }

    public function down(): void
    {
        Schema::connection('payment')->dropIfExists('payment.bank_provider_documentation_gaps');
        Schema::connection('payment')->dropIfExists('payment.reconciliation_exceptions');
    }
};
