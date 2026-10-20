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
        Schema::connection('ledger')->create('funding.funding_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('receipt_reference')->unique()->notNullable();
            $table->uuid('funding_transaction_id')->unique()->notNullable();
            $table->bigInteger('amount')->notNullable();
            $table->bigInteger('fee_amount')->default(0)->notNullable();
            $table->bigInteger('net_amount')->notNullable();
            $table->char('currency_code', 3)->notNullable();
            $table->string('funding_method', 32)->notNullable();
            $table->uuid('agent_id')->nullable();
            $table->uuid('customer_id')->notNullable();
            $table->uuid('wallet_id')->notNullable();
            $table->uuid('ledger_journal_id')->notNullable();
            $table->timestampTz('completed_at')->notNullable();
            $table->timestampsTz();
        });

        DB::connection('ledger')->statement(
            'CREATE INDEX funding_receipts_customer_idx ON funding.funding_receipts (customer_id, created_at)'
        );
        DB::connection('ledger')->statement(
            'CREATE INDEX funding_receipts_agent_idx ON funding.funding_receipts (agent_id, created_at)'
        );
    }

    public function down(): void
    {
        Schema::connection('ledger')->dropIfExists('funding.funding_receipts');
    }
};
