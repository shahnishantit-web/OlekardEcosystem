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
        Schema::connection('platform')->table('multi_currency.fx_rates', function (Blueprint $table): void {
            $table->string('status', 20)->default('active')->after('change_request_id');
        });

        // Backfill: non-superseded existing records are active
        DB::connection('platform')->statement(
            "UPDATE multi_currency.fx_rates SET status = 'active' WHERE effective_to IS NULL"
        );
        DB::connection('platform')->statement(
            "UPDATE multi_currency.fx_rates SET status = 'superseded' WHERE effective_to IS NOT NULL"
        );

        // Replace the old effective_to-based unique index with a status-based one
        DB::connection('platform')->statement(
            "DROP INDEX IF EXISTS multi_currency.idx_fx_rates_active_pair"
        );
        DB::connection('platform')->statement(
            "CREATE UNIQUE INDEX idx_fx_rates_active_pair
             ON multi_currency.fx_rates (from_currency_code, to_currency_code)
             WHERE status = 'active'"
        );
    }

    public function down(): void
    {
        DB::connection('platform')->statement(
            "DROP INDEX IF EXISTS multi_currency.idx_fx_rates_active_pair"
        );
        DB::connection('platform')->statement(
            "CREATE UNIQUE INDEX idx_fx_rates_active_pair
             ON multi_currency.fx_rates (from_currency_code, to_currency_code)
             WHERE effective_to IS NULL"
        );

        Schema::connection('platform')->table('multi_currency.fx_rates', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }
};
