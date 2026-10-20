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
        Schema::connection('platform')->create('commission.commission_payouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('agent_id');
            $table->integer('total_amount');
            $table->string('currency_code', 3);
            $table->string('status', 12)->default('approved');
            $table->string('approved_by', 36);
            $table->timestampTz('approved_at')->useCurrent();
            $table->timestampTz('executed_at')->nullable();
            $table->string('cancelled_by', 36)->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('journal_id', 36)->nullable();
            $table->string('idempotency_key', 255);
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            "ALTER TABLE commission.commission_payouts
             ADD CONSTRAINT commission_payouts_status_check CHECK (status IN ('approved','completed','deferred','failed','cancelled'))"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE commission.commission_payouts
             ADD CONSTRAINT commission_payouts_amount_check CHECK (total_amount > 0)"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE commission.commission_payouts
             ADD CONSTRAINT commission_payouts_agent_id_fk
             FOREIGN KEY (agent_id) REFERENCES agent.agents(id)"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE commission.commissions
             ADD CONSTRAINT commissions_payout_id_fk
             FOREIGN KEY (payout_id) REFERENCES commission.commission_payouts(id)"
        );

        DB::connection('platform')->statement(
            "CREATE UNIQUE INDEX idx_commission_payouts_idempotency ON commission.commission_payouts (idempotency_key)"
        );

        DB::connection('platform')->statement(
            "CREATE INDEX idx_commission_payouts_agent_status ON commission.commission_payouts (agent_id, status)"
        );

        DB::connection('platform')->statement(
            "CREATE INDEX idx_commission_payouts_deferred
             ON commission.commission_payouts (agent_id, status) WHERE status = 'deferred'"
        );
    }

    public function down(): void
    {
        DB::connection('platform')->statement(
            "ALTER TABLE commission.commissions DROP CONSTRAINT IF EXISTS commissions_payout_id_fk"
        );

        Schema::connection('platform')->dropIfExists('commission.commission_payouts');
    }
};
