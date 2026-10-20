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
        Schema::connection('platform')->create('multi_currency.fx_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('from_currency_code', 3);
            $table->string('to_currency_code', 3);
            $table->decimal('rate', 18, 8);
            $table->decimal('spread', 6, 5)->default(0);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->uuid('created_by');
            $table->uuid('approved_by')->nullable();
            $table->uuid('change_request_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        DB::connection('platform')->statement(
            "ALTER TABLE multi_currency.fx_rates
             ADD CONSTRAINT fk_fx_rates_from_currency
             FOREIGN KEY (from_currency_code) REFERENCES multi_currency.currencies(code)"
        );
        DB::connection('platform')->statement(
            "ALTER TABLE multi_currency.fx_rates
             ADD CONSTRAINT fk_fx_rates_to_currency
             FOREIGN KEY (to_currency_code) REFERENCES multi_currency.currencies(code)"
        );
        DB::connection('platform')->statement(
            "ALTER TABLE multi_currency.fx_rates
             ADD CONSTRAINT fx_rates_different_currencies
             CHECK (from_currency_code != to_currency_code)"
        );
        DB::connection('platform')->statement(
            "CREATE UNIQUE INDEX idx_fx_rates_active_pair
             ON multi_currency.fx_rates (from_currency_code, to_currency_code)
             WHERE effective_to IS NULL"
        );
        DB::connection('platform')->statement(
            "CREATE INDEX idx_fx_rates_pair_history
             ON multi_currency.fx_rates (from_currency_code, to_currency_code, effective_from DESC)"
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('multi_currency.fx_rates');
    }
};
