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
        Schema::connection('payment')->create('payment.payment_refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payment_intent_id')->notNullable();
            $table->uuid('payment_attempt_id')->notNullable();
            $table->bigInteger('amount_minor')->notNullable();
            $table->string('status', 32)->notNullable();
            $table->string('idempotency_key', 255)->notNullable();
            $table->uuid('requested_by')->notNullable();
            $table->uuid('approved_by')->nullable();
            $table->string('provider_refund_reference', 500)->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('failure_reason', 128)->nullable();
            $table->timestampTz('requested_at')->notNullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('created_at')->notNullable()->useCurrent();
            $table->timestampTz('updated_at')->notNullable()->useCurrent();
        });

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_refunds ADD CONSTRAINT payment_refunds_amount_check
             CHECK (amount_minor > 0)"
        );
        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_refunds ADD CONSTRAINT payment_refunds_status_check
             CHECK (status IN ('requested','pending_approval','processing','succeeded','failed','cancelled'))"
        );

        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_refunds
             ADD CONSTRAINT payment_refunds_intent_id_fk
             FOREIGN KEY (payment_intent_id) REFERENCES payment.payment_intents(id)'
        );
        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_refunds
             ADD CONSTRAINT payment_refunds_attempt_id_fk
             FOREIGN KEY (payment_attempt_id) REFERENCES payment.payment_attempts(id)'
        );

        DB::connection('payment')->statement(
            'CREATE UNIQUE INDEX payment_refunds_idempotency_idx
             ON payment.payment_refunds (idempotency_key)'
        );
        DB::connection('payment')->statement(
            'CREATE INDEX payment_refunds_intent_status_idx
             ON payment.payment_refunds (payment_intent_id, status)'
        );
        DB::connection('payment')->statement(
            'CREATE INDEX payment_refunds_attempt_id_idx
             ON payment.payment_refunds (payment_attempt_id)'
        );
    }

    public function down(): void
    {
        Schema::connection('payment')->dropIfExists('payment.payment_refunds');
    }
};
