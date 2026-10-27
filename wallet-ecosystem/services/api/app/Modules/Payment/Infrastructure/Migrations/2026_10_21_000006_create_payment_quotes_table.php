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
        Schema::connection('payment')->create('payment.payment_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payment_intent_id')->notNullable();
            $table->bigInteger('version')->notNullable()->default(1);
            $table->string('quote_status', 32)->notNullable()->default('active');
            $table->bigInteger('base_amount_minor')->notNullable();
            $table->bigInteger('fee_amount_minor')->notNullable()->default(0);
            $table->bigInteger('total_amount_minor')->notNullable();
            $table->string('currency_code', 3)->notNullable();
            $table->string('provider_code', 64)->notNullable();
            $table->string('rounding_mode', 32)->notNullable()->default('floor');
            $table->jsonb('fee_rule_snapshot')->notNullable()->default('{}');
            $table->jsonb('obligation_snapshot')->notNullable()->default('{}');
            $table->timestampTz('expires_at')->notNullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('created_at')->notNullable()->useCurrent();
        });

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_quotes
             ADD CONSTRAINT chk_quote_amounts
             CHECK (base_amount_minor > 0 AND fee_amount_minor >= 0 AND total_amount_minor > 0)"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_quotes
             ADD CONSTRAINT chk_quote_status
             CHECK (quote_status IN ('active','expired','superseded','consumed','cancelled'))"
        );

        DB::connection('payment')->statement(
            'CREATE INDEX payment_quotes_intent_status_idx
             ON payment.payment_quotes (payment_intent_id, quote_status)'
        );

        DB::connection('payment')->statement(
            "CREATE INDEX payment_quotes_expires_idx
             ON payment.payment_quotes (expires_at)
             WHERE quote_status = 'active'"
        );
    }

    public function down(): void
    {
        Schema::connection('payment')->dropIfExists('payment.payment_quotes');
    }
};
