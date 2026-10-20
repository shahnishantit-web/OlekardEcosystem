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
        Schema::connection('platform')->create('multi_currency.fx_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->string('from_currency_code', 3);
            $table->string('to_currency_code', 3);
            $table->decimal('from_amount', 18, 4);
            $table->decimal('to_amount', 18, 4);
            $table->decimal('fee_amount', 18, 4)->default(0);
            $table->decimal('rate', 18, 8);
            $table->uuid('fx_rate_id');
            $table->string('status', 20)->default('quoted');
            $table->timestampTz('expires_at');
            $table->string('idempotency_key', 255)->unique();
            $table->uuid('fx_transaction_id')->nullable();
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            "ALTER TABLE multi_currency.fx_quotes
             ADD CONSTRAINT fx_quotes_status_check
             CHECK (status IN ('quoted','confirmed','expired','cancelled'))"
        );
        DB::connection('platform')->statement(
            "ALTER TABLE multi_currency.fx_quotes
             ADD CONSTRAINT fk_fx_quotes_fx_rate
             FOREIGN KEY (fx_rate_id) REFERENCES multi_currency.fx_rates(id)"
        );
        DB::connection('platform')->statement(
            "CREATE INDEX idx_fx_quotes_customer_status
             ON multi_currency.fx_quotes (customer_id, status)"
        );
        DB::connection('platform')->statement(
            "CREATE INDEX idx_fx_quotes_expires_at
             ON multi_currency.fx_quotes (expires_at)
             WHERE status = 'quoted'"
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('multi_currency.fx_quotes');
    }
};
