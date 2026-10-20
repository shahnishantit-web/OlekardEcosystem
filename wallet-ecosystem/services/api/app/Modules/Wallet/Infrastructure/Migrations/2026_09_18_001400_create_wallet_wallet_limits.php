<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'wallet';

    public function up(): void
    {
        Schema::connection('wallet')->create('wallet.wallet_limits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id');
            $table->string('currency_code', 10);
            $table->string('limit_type', 50);
            $table->bigInteger('limit_value')->nullable();
            $table->boolean('is_tbd')->default(true);
            $table->timestampTz('effective_from')->useCurrent();
            $table->uuid('created_by');
            $table->uuid('updated_by');
            $table->timestampsTz();
        });

        DB::connection('wallet')->statement(
            "ALTER TABLE wallet.wallet_limits ADD CONSTRAINT wallet_limits_type_check CHECK (limit_type IN ('daily_transaction_amount','weekly_transaction_amount','monthly_transaction_amount','single_transaction_amount','max_balance'))"
        );

        DB::connection('wallet')->statement(
            'ALTER TABLE wallet.wallet_limits ADD CONSTRAINT wallet_limits_unique UNIQUE (wallet_id, currency_code, limit_type)'
        );

        DB::connection('wallet')->statement(
            'ALTER TABLE wallet.wallet_limits ADD CONSTRAINT wallet_limits_wallet_id_fk FOREIGN KEY (wallet_id) REFERENCES wallet.wallets(id)'
        );
    }

    public function down(): void
    {
        Schema::connection('wallet')->dropIfExists('wallet.wallet_limits');
    }
};
