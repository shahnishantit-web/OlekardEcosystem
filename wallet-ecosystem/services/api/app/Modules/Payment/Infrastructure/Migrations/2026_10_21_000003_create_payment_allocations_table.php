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
        Schema::connection('payment')->create('payment.payment_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payment_intent_id')->notNullable();
            $table->string('allocation_type', 64)->notNullable();
            $table->string('recipient_type', 64)->notNullable();
            $table->string('recipient_reference', 255)->nullable();
            $table->bigInteger('amount_minor')->notNullable();
            $table->char('currency_code', 3)->notNullable();
            $table->string('status', 32)->notNullable()->default('allocated');
            $table->string('idempotency_key', 255)->notNullable();
            $table->timestampTz('created_at')->notNullable()->useCurrent();
            $table->timestampTz('updated_at')->notNullable()->useCurrent();
        });

        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_allocations
             ADD CONSTRAINT payment_allocations_intent_fk
             FOREIGN KEY (payment_intent_id)
             REFERENCES payment.payment_intents(id)'
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_allocations
             ADD CONSTRAINT payment_allocations_amount_check
             CHECK (amount_minor > 0)"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_allocations
             ADD CONSTRAINT payment_allocations_status_check
             CHECK (status IN ('allocated','settled','refunded','cancelled'))"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_allocations
             ADD CONSTRAINT payment_allocations_allocation_type_check
             CHECK (allocation_type IN ('org_disbursement','olekard_fee','agent_commission','platform_reserve','other'))"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_allocations
             ADD CONSTRAINT payment_allocations_recipient_type_check
             CHECK (recipient_type IN ('organisation','olekard','agent','system'))"
        );

        DB::connection('payment')->statement(
            'CREATE UNIQUE INDEX payment_allocations_idempotency_idx
             ON payment.payment_allocations (idempotency_key)'
        );

        DB::connection('payment')->statement(
            "CREATE UNIQUE INDEX payment_allocations_intent_type_recipient_idx
             ON payment.payment_allocations (payment_intent_id, allocation_type, recipient_reference)
             WHERE status != 'cancelled'"
        );

        DB::connection('payment')->statement(
            'CREATE INDEX payment_allocations_intent_status_idx
             ON payment.payment_allocations (payment_intent_id, status)'
        );
    }

    public function down(): void
    {
        Schema::connection('payment')->dropIfExists('payment.payment_allocations');
    }
};
