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
        Schema::connection('platform')->create('multi_currency.fx_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->uuid('quote_id');
            $table->uuid('from_wallet_id');
            $table->uuid('to_wallet_id');
            $table->string('from_currency_code', 3);
            $table->string('to_currency_code', 3);
            $table->decimal('from_amount', 18, 4);
            $table->decimal('to_amount', 18, 4);
            $table->decimal('fee_amount', 18, 4)->default(0);
            $table->decimal('rate', 18, 8);
            $table->string('status', 20);
            $table->text('failure_reason')->nullable();
            $table->string('idempotency_key', 255)->unique();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        DB::connection('platform')->statement(
            "ALTER TABLE multi_currency.fx_transactions
             ADD CONSTRAINT fx_transactions_status_check
             CHECK (status IN ('completed','failed'))"
        );
        DB::connection('platform')->statement(
            "ALTER TABLE multi_currency.fx_transactions
             ADD CONSTRAINT fk_fx_transactions_quote
             FOREIGN KEY (quote_id) REFERENCES multi_currency.fx_quotes(id)"
        );
        DB::connection('platform')->statement(
            "CREATE INDEX idx_fx_transactions_customer
             ON multi_currency.fx_transactions (customer_id, created_at DESC)"
        );
        DB::connection('platform')->statement(
            "CREATE INDEX idx_fx_transactions_quote
             ON multi_currency.fx_transactions (quote_id)"
        );

        // Back-fill the FK from fx_quotes to fx_transactions (set on confirm)
        DB::connection('platform')->statement(
            "ALTER TABLE multi_currency.fx_quotes
             ADD CONSTRAINT fk_fx_quotes_fx_transaction
             FOREIGN KEY (fx_transaction_id) REFERENCES multi_currency.fx_transactions(id)"
        );
    }

    public function down(): void
    {
        DB::connection('platform')->statement(
            "ALTER TABLE multi_currency.fx_quotes DROP CONSTRAINT IF EXISTS fk_fx_quotes_fx_transaction"
        );
        Schema::connection('platform')->dropIfExists('multi_currency.fx_transactions');
    }
};
