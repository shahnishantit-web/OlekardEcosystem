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
        Schema::connection('platform')->create('withdrawal.withdrawal_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('receipt_reference')->unique()->notNullable();
            $table->uuid('withdrawal_transaction_id')->unique()->notNullable();
            $table->bigInteger('gross_amount')->notNullable();
            $table->bigInteger('fee_amount')->default(0)->notNullable();
            $table->bigInteger('net_payout_amount')->notNullable();
            $table->char('currency_code', 3)->notNullable();
            $table->string('channel', 32)->notNullable();
            $table->string('payout_destination_masked', 255)->notNullable();
            $table->string('provider_reference_id', 255)->nullable();
            $table->uuid('customer_id')->notNullable();
            $table->uuid('wallet_id')->notNullable();
            $table->string('ledger_journal_id', 100)->notNullable();
            $table->timestampTz('completed_at')->notNullable();
            $table->timestampsTz();
        });

        DB::statement("CREATE INDEX wr_customer_idx ON withdrawal.withdrawal_receipts (customer_id)");
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('withdrawal.withdrawal_receipts');
    }
};
