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
        Schema::connection('platform')->create('commission.commissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('agent_id');
            $table->string('transaction_id', 36);
            $table->string('transaction_type', 8);
            $table->integer('amount');
            $table->string('currency_code', 3);
            $table->uuid('rule_id');
            $table->string('status', 10)->default('pending');
            $table->uuid('payout_id')->nullable();
            $table->string('journal_id', 36);
            $table->string('reversal_journal_id', 36)->nullable();
            $table->string('idempotency_key', 255);
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            "ALTER TABLE commission.commissions
             ADD CONSTRAINT commissions_transaction_type_check CHECK (transaction_type IN ('cash_in','cash_out'))"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE commission.commissions
             ADD CONSTRAINT commissions_status_check CHECK (status IN ('pending','approved','paid','reversed'))"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE commission.commissions
             ADD CONSTRAINT commissions_amount_check CHECK (amount > 0)"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE commission.commissions
             ADD CONSTRAINT commissions_agent_id_fk
             FOREIGN KEY (agent_id) REFERENCES agent.agents(id)"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE commission.commissions
             ADD CONSTRAINT commissions_rule_id_fk
             FOREIGN KEY (rule_id) REFERENCES commission.commission_rules(id)"
        );

        DB::connection('platform')->statement(
            "CREATE UNIQUE INDEX idx_commissions_idempotency ON commission.commissions (idempotency_key)"
        );

        DB::connection('platform')->statement(
            "CREATE INDEX idx_commissions_agent_status ON commission.commissions (agent_id, status)"
        );

        DB::connection('platform')->statement(
            "CREATE INDEX idx_commissions_agent_created ON commission.commissions (agent_id, created_at DESC)"
        );

        DB::connection('platform')->statement(
            "CREATE INDEX idx_commissions_payout ON commission.commissions (payout_id) WHERE payout_id IS NOT NULL"
        );

        DB::connection('platform')->statement(
            "CREATE INDEX idx_commissions_agent_month
             ON commission.commissions (agent_id, created_at DESC) WHERE status != 'reversed'"
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('commission.commissions');
    }
};
