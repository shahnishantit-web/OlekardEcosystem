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
        Schema::connection('platform')->create('agent.float_limits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('agent_id')->unique();
            $table->char('currency_code', 3)->default('BWP');
            // All limits start as TBD (null value, is_tbd=true) until admin explicitly sets them
            $table->bigInteger('max_balance')->nullable();
            $table->boolean('max_balance_is_tbd')->default(true);
            $table->bigInteger('per_transaction_limit')->nullable();
            $table->boolean('per_tx_is_tbd')->default(true);
            $table->bigInteger('daily_volume_limit')->nullable();
            $table->boolean('daily_vol_is_tbd')->default(true);
            $table->bigInteger('low_float_threshold')->nullable();
            $table->boolean('low_threshold_is_tbd')->default(true);
            $table->boolean('low_float_alerted')->default(false);
            $table->timestampTz('effective_from')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            'ALTER TABLE agent.float_limits ADD CONSTRAINT float_limits_agent_fk
             FOREIGN KEY (agent_id) REFERENCES agent.agents(id)'
        );

        DB::connection('platform')->statement(
            'CREATE UNIQUE INDEX float_limits_agent_id_unique_idx ON agent.float_limits (agent_id)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('agent.float_limits');
    }
};
