<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'reconciliation';

    public function up(): void
    {
        Schema::connection('reconciliation')->create('reconciliation.settlement_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('idempotency_key', 128)->unique();
            $table->string('settlement_type', 30);
            $table->string('provider_code', 50)->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->char('currency_code', 3)->default('BWP');
            $table->decimal('gross_amount', 18, 4)->default(0);
            $table->decimal('fee_amount', 18, 4)->default(0);
            $table->decimal('net_amount', 18, 4)->default(0);
            $table->string('status', 20)->default('draft');
            $table->uuid('maker_id');
            $table->uuid('checker_id')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->string('ledger_transaction_id', 128)->nullable();
            $table->string('external_reference', 256)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
        });

        DB::connection('reconciliation')->statement("
            ALTER TABLE reconciliation.settlement_batches
            ADD CONSTRAINT chk_settlement_status
            CHECK (status IN ('draft','pending_approval','approved','executing','executed','rejected','failed'))
        ");
        DB::connection('reconciliation')->statement("
            ALTER TABLE reconciliation.settlement_batches
            ADD CONSTRAINT chk_settlement_type
            CHECK (settlement_type IN ('provider_settlement','bank_settlement'))
        ");
        DB::connection('reconciliation')->statement("
            ALTER TABLE reconciliation.settlement_batches
            ADD CONSTRAINT chk_maker_checker_diff
            CHECK (checker_id IS NULL OR maker_id != checker_id)
        ");
        DB::connection('reconciliation')->statement("
            ALTER TABLE reconciliation.settlement_batches
            ADD CONSTRAINT chk_settlement_gross_positive CHECK (gross_amount >= 0)
        ");
        DB::connection('reconciliation')->statement("
            ALTER TABLE reconciliation.settlement_batches
            ADD CONSTRAINT chk_settlement_fee_positive CHECK (fee_amount >= 0)
        ");
        DB::connection('reconciliation')->statement("
            ALTER TABLE reconciliation.settlement_batches
            ADD CONSTRAINT chk_settlement_net_positive CHECK (net_amount >= 0)
        ");
        DB::connection('reconciliation')->statement('
            CREATE INDEX idx_settlement_status_created
            ON reconciliation.settlement_batches (status, created_at DESC)
        ');
        DB::connection('reconciliation')->statement('
            CREATE INDEX idx_settlement_maker
            ON reconciliation.settlement_batches (maker_id, created_at DESC)
        ');
        DB::connection('reconciliation')->statement('
            CREATE INDEX idx_settlement_period
            ON reconciliation.settlement_batches (period_start, period_end, settlement_type)
        ');
    }

    public function down(): void
    {
        Schema::connection('reconciliation')->dropIfExists('reconciliation.settlement_batches');
    }
};
