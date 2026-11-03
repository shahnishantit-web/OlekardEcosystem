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
        Schema::connection('payment')->table('payment.payment_attempts', function (Blueprint $table): void {
            $table->bigInteger('captured_amount_minor')->nullable();
            $table->char('captured_currency_code', 3)->nullable();
            $table->uuid('capture_evidence_reference')->nullable();
            $table->string('ledger_debit_account_reference', 255)->nullable();
            $table->string('ledger_credit_account_reference', 255)->nullable();
        });

        Schema::connection('payment')->create('payment.fulfilment_acknowledgements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payment_intent_id')->unique();
            $table->string('organisation_id', 128);
            $table->string('purpose_type', 128);
            $table->string('purpose_reference', 255);
            $table->string('consumer_service_id', 128);
            $table->string('fulfilment_reference', 255);
            $table->string('idempotency_key', 255)->unique();
            $table->timestampTz('acknowledged_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::connection('payment')->statement(
            'ALTER TABLE payment.fulfilment_acknowledgements ADD CONSTRAINT fulfilment_ack_intent_fk FOREIGN KEY (payment_intent_id) REFERENCES payment.payment_intents(id)'
        );
    }

    public function down(): void
    {
        Schema::connection('payment')->dropIfExists('payment.fulfilment_acknowledgements');
        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_attempts DROP COLUMN IF EXISTS captured_amount_minor, DROP COLUMN IF EXISTS captured_currency_code, DROP COLUMN IF EXISTS capture_evidence_reference, DROP COLUMN IF EXISTS ledger_debit_account_reference, DROP COLUMN IF EXISTS ledger_credit_account_reference'
        );
    }
};
