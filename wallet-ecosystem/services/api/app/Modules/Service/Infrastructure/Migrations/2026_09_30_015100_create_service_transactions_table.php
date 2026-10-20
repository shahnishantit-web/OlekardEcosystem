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
        Schema::connection('platform')->create('service.service_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('idempotency_key', 255)->notNullable()->unique();
            $table->uuid('customer_id')->notNullable();
            $table->uuid('wallet_id')->notNullable();
            $table->uuid('service_id')->notNullable();
            $table->string('customer_reference', 255)->notNullable();
            $table->string('account_name', 255)->nullable();
            $table->bigInteger('amount')->notNullable();
            $table->bigInteger('fee_amount')->notNullable()->default(0);
            $table->bigInteger('total_amount')->notNullable();
            $table->char('currency_code', 10)->notNullable();
            $table->string('status', 50)->notNullable()->default('initiated');
            $table->uuid('journal_id')->nullable();
            $table->string('provider_code', 100)->notNullable();
            $table->string('provider_reference', 255)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->smallInteger('requery_count')->notNullable()->default(0);
            $table->boolean('review_flag')->notNullable()->default(false);
            $table->string('correlation_id', 255)->notNullable();
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            "ALTER TABLE service.service_transactions
             ADD CONSTRAINT service_transactions_status_check
             CHECK (status IN ('initiated','pending_provider','completed','failed','timeout_pending_review','abandoned'))"
        );
        DB::connection('platform')->statement(
            "ALTER TABLE service.service_transactions
             ADD CONSTRAINT service_transactions_amount_check CHECK (amount > 0)"
        );
        DB::connection('platform')->statement(
            "ALTER TABLE service.service_transactions
             ADD CONSTRAINT service_transactions_fee_amount_check CHECK (fee_amount >= 0)"
        );
        DB::connection('platform')->statement(
            "CREATE INDEX service_transactions_customer_status_idx
             ON service.service_transactions (customer_id, status, created_at DESC)"
        );
        DB::connection('platform')->statement(
            "CREATE INDEX service_transactions_service_idx
             ON service.service_transactions (service_id, created_at DESC)"
        );
        DB::connection('platform')->statement(
            "CREATE INDEX service_transactions_status_updated_idx
             ON service.service_transactions (status, updated_at)"
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('service.service_transactions');
    }
};
