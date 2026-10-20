<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ledger';

    public function up(): void
    {
        DB::connection('ledger')->statement('CREATE SCHEMA IF NOT EXISTS funding');

        Schema::connection('ledger')->create('funding.funding_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id')->notNullable();
            $table->char('pocket_currency_code', 3)->notNullable();
            $table->uuid('customer_id')->notNullable();
            $table->uuid('agent_id')->nullable();
            $table->string('funding_method', 32)->notNullable();
            $table->string('status', 32)->default('pending')->notNullable();
            $table->bigInteger('amount')->notNullable();
            $table->bigInteger('fee_amount')->default(0)->notNullable();
            $table->bigInteger('net_amount')->notNullable();
            $table->string('idempotency_key', 128)->unique()->notNullable();
            $table->string('provider_reference_id', 255)->nullable();
            $table->string('bank_reference_code', 64)->unique()->nullable();
            $table->timestampTz('bank_reference_expires_at')->nullable();
            $table->uuid('ledger_journal_id')->nullable();
            $table->uuid('transaction_reference')->unique()->notNullable();
            $table->string('failure_reason', 64)->nullable();
            $table->uuid('initiated_by')->notNullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();
        });

        DB::connection('ledger')->statement(
            "ALTER TABLE funding.funding_transactions ADD CONSTRAINT funding_transactions_amount_check
             CHECK (amount > 0)"
        );
        DB::connection('ledger')->statement(
            "ALTER TABLE funding.funding_transactions ADD CONSTRAINT funding_transactions_fee_amount_check
             CHECK (fee_amount >= 0)"
        );
        DB::connection('ledger')->statement(
            "ALTER TABLE funding.funding_transactions ADD CONSTRAINT funding_transactions_net_amount_check
             CHECK (net_amount >= 0)"
        );
        DB::connection('ledger')->statement(
            "ALTER TABLE funding.funding_transactions ADD CONSTRAINT funding_transactions_method_check
             CHECK (funding_method IN ('mobile_money','bank_transfer','card','agent'))"
        );
        DB::connection('ledger')->statement(
            "ALTER TABLE funding.funding_transactions ADD CONSTRAINT funding_transactions_status_check
             CHECK (status IN ('pending','processing','awaiting_match','completed','failed','cancelled'))"
        );

        DB::connection('ledger')->statement(
            'CREATE INDEX funding_transactions_customer_status_idx ON funding.funding_transactions (customer_id, status, created_at)'
        );
        DB::connection('ledger')->statement(
            'CREATE INDEX funding_transactions_provider_ref_idx ON funding.funding_transactions (provider_reference_id)'
        );
        DB::connection('ledger')->statement(
            'CREATE INDEX funding_transactions_wallet_status_idx ON funding.funding_transactions (wallet_id, status)'
        );
        DB::connection('ledger')->statement(
            'CREATE INDEX funding_transactions_agent_idx ON funding.funding_transactions (agent_id, created_at)'
        );
        DB::connection('ledger')->statement(
            'CREATE INDEX funding_transactions_bank_ref_match_idx ON funding.funding_transactions (bank_reference_code, status, bank_reference_expires_at)'
        );
    }

    public function down(): void
    {
        Schema::connection('ledger')->dropIfExists('funding.funding_transactions');
    }
};
