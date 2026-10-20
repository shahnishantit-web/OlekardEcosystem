<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        Schema::connection('platform')->create('qr.qr_payment_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('idempotency_key', 255)->notNullable()->unique();
            $table->string('correlation_id', 255)->notNullable()->index();
            $table->uuid('qr_code_id')->notNullable()->index();
            $table->uuid('customer_wallet_id')->notNullable()->index();
            $table->uuid('merchant_wallet_id')->notNullable();
            $table->bigInteger('amount')->notNullable();
            $table->bigInteger('fee')->notNullable()->default(0);
            $table->char('currency', 3)->notNullable()->default('BWP');
            $table->string('status', 20)->notNullable()->default('initiated');
            $table->uuid('journal_id')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->boolean('step_up_verified')->notNullable()->default(false);
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            "ALTER TABLE qr.qr_payment_transactions
             ADD CONSTRAINT qr_payment_transactions_status_check CHECK (status IN ('initiated','completed','failed'))"
        );
        DB::connection('platform')->statement(
            "ALTER TABLE qr.qr_payment_transactions
             ADD CONSTRAINT qr_payment_transactions_amount_check CHECK (amount > 0)"
        );
        DB::connection('platform')->statement(
            "ALTER TABLE qr.qr_payment_transactions
             ADD CONSTRAINT qr_payment_transactions_fee_check CHECK (fee >= 0)"
        );
        DB::connection('platform')->statement(
            'CREATE INDEX qr_payment_transactions_customer_status_idx ON qr.qr_payment_transactions (customer_wallet_id, status)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('qr.qr_payment_transactions');
    }
};
