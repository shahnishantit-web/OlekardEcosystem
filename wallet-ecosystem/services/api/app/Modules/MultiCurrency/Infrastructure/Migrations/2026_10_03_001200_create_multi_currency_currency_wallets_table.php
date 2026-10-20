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
        Schema::connection('platform')->create('multi_currency.currency_wallets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->string('currency_code', 3);
            $table->uuid('wallet_id');
            $table->string('pocket_ledger_account_reference', 36);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['customer_id', 'currency_code']);
        });

        DB::connection('platform')->statement(
            "CREATE INDEX idx_currency_wallets_customer ON multi_currency.currency_wallets (customer_id)"
        );
        DB::connection('platform')->statement(
            "CREATE INDEX idx_currency_wallets_wallet_id ON multi_currency.currency_wallets (wallet_id)"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE multi_currency.currency_wallets
             ADD CONSTRAINT fk_currency_wallets_currency
             FOREIGN KEY (currency_code) REFERENCES multi_currency.currencies(code)"
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('multi_currency.currency_wallets');
    }
};
