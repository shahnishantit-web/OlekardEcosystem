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
        Schema::connection('platform')->create('multi_currency.same_currency_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->uuid('from_wallet_id');
            $table->uuid('to_wallet_id');
            $table->string('currency_code', 3);
            $table->decimal('amount', 18, 4);
            $table->string('idempotency_key', 255)->unique();
            $table->timestamp('created_at')->useCurrent();
        });

        DB::connection('platform')->statement(
            "CREATE INDEX idx_same_currency_transfers_customer
             ON multi_currency.same_currency_transfers (customer_id, created_at DESC)"
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('multi_currency.same_currency_transfers');
    }
};
