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
        Schema::connection('payment')->create('payment.payment_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payment_intent_id')->notNullable();
            $table->string('provider_code', 64)->notNullable();
            $table->uuid('provider_account_id')->notNullable();
            $table->string('status', 32)->notNullable();
            $table->string('idempotency_key', 255)->notNullable();
            $table->string('provider_reference_id', 500)->nullable();
            $table->uuid('wallet_hold_id')->nullable();
            $table->uuid('ledger_journal_id')->nullable();
            $table->string('next_action_type', 64)->nullable();
            $table->jsonb('next_action_data')->nullable();
            $table->bigInteger('fee_minor')->default(0)->notNullable();
            $table->timestampTz('initiated_at')->notNullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('failure_reason', 128)->nullable();
            $table->timestampTz('timeout_at')->nullable();
            $table->timestampTz('created_at')->notNullable()->useCurrent();
            $table->timestampTz('updated_at')->notNullable()->useCurrent();
        });

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_attempts ADD CONSTRAINT payment_attempts_status_check
             CHECK (status IN (
               'initiated','processing','awaiting_action',
               'succeeded','failed','cancelled','expired','timed_out'
             ))"
        );

        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_attempts
             ADD CONSTRAINT payment_attempts_intent_id_fk
             FOREIGN KEY (payment_intent_id) REFERENCES payment.payment_intents(id)'
        );
        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_attempts
             ADD CONSTRAINT payment_attempts_account_id_fk
             FOREIGN KEY (provider_account_id) REFERENCES payment.payment_provider_accounts(id)'
        );

        DB::connection('payment')->statement(
            'CREATE UNIQUE INDEX payment_attempts_idempotency_idx
             ON payment.payment_attempts (idempotency_key)'
        );
        DB::connection('payment')->statement(
            'CREATE INDEX payment_attempts_intent_status_idx
             ON payment.payment_attempts (payment_intent_id, status)'
        );
        DB::connection('payment')->statement(
            'CREATE INDEX payment_attempts_provider_ref_idx
             ON payment.payment_attempts (provider_code, provider_reference_id)'
        );
        DB::connection('payment')->statement(
            "CREATE INDEX payment_attempts_timeout_at_idx
             ON payment.payment_attempts (timeout_at)
             WHERE status NOT IN ('succeeded','failed','cancelled','expired')"
        );
    }

    public function down(): void
    {
        Schema::connection('payment')->dropIfExists('payment.payment_attempts');
    }
};
