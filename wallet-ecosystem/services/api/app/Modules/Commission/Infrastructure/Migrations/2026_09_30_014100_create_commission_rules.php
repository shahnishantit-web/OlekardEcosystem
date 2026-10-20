<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection('platform')->statement("CREATE SCHEMA IF NOT EXISTS commission");

        Schema::connection('platform')->create('commission.commission_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('rule_type', 12);
            $table->integer('rate');
            $table->string('applies_to', 8);
            $table->string('agent_tier', 50)->nullable();
            $table->string('status', 12)->default('active');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->bigInteger('volume_threshold')->nullable();
            $table->bigInteger('min_amount')->nullable();
            $table->bigInteger('max_amount')->nullable();
            $table->string('created_by', 36);
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            "ALTER TABLE commission.commission_rules
             ADD CONSTRAINT commission_rules_rule_type_check CHECK (rule_type IN ('flat','percentage'))"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE commission.commission_rules
             ADD CONSTRAINT commission_rules_applies_to_check CHECK (applies_to IN ('cash_in','cash_out','both'))"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE commission.commission_rules
             ADD CONSTRAINT commission_rules_status_check CHECK (status IN ('active','superseded','expired'))"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE commission.commission_rules
             ADD CONSTRAINT commission_rules_rate_check CHECK (rate > 0)"
        );

        DB::connection('platform')->statement(
            "CREATE INDEX idx_commission_rules_lookup
             ON commission.commission_rules (applies_to, status, valid_from, valid_to)
             WHERE status = 'active'"
        );

        DB::connection('platform')->statement(
            "CREATE INDEX idx_commission_rules_tier_lookup
             ON commission.commission_rules (agent_tier, applies_to, status)
             WHERE status = 'active' AND agent_tier IS NOT NULL"
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('commission.commission_rules');
    }
};
