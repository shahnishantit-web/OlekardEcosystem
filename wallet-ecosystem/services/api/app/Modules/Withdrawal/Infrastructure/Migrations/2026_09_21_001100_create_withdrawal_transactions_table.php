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
        Schema::connection('platform')->create('withdrawal.withdrawal_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id')->notNullable();
            $table->char('pocket_currency_code', 3)->notNullable();
            $table->uuid('customer_id')->notNullable();
            $table->string('channel', 32)->notNullable();
            $table->string('status', 20)->default('pending')->notNullable();
            $table->bigInteger('gross_amount')->notNullable();
            $table->bigInteger('fee_amount')->default(0)->notNullable();
            $table->bigInteger('net_payout_amount')->notNullable();
            $table->integer('fee_rate_bps')->default(0)->notNullable();
            $table->string('idempotency_key', 255)->nullable();
            $table->jsonb('payout_destination')->notNullable();
            $table->uuid('wallet_hold_id')->nullable();
            $table->string('provider_reference_id', 255)->nullable();
            $table->string('risk_check_status', 32)->default('pending')->notNullable();
            $table->string('failure_reason', 128)->nullable();
            $table->string('ledger_journal_id', 100)->nullable();
            $table->uuid('transaction_reference')->notNullable();
            $table->uuid('initiated_by')->notNullable();
            $table->uuid('correlation_id')->notNullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE withdrawal.withdrawal_transactions ADD CONSTRAINT wt_gross_amount_check CHECK (gross_amount > 0)");
        DB::statement("ALTER TABLE withdrawal.withdrawal_transactions ADD CONSTRAINT wt_fee_amount_check CHECK (fee_amount >= 0)");
        DB::statement("ALTER TABLE withdrawal.withdrawal_transactions ADD CONSTRAINT wt_net_payout_check CHECK (net_payout_amount > 0)");
        DB::statement("ALTER TABLE withdrawal.withdrawal_transactions ADD CONSTRAINT wt_amounts_check CHECK (net_payout_amount = gross_amount - fee_amount)");
        DB::statement("ALTER TABLE withdrawal.withdrawal_transactions ADD CONSTRAINT wt_status_check CHECK (status IN ('pending','processing','completed','failed','cancelled'))");
        DB::statement("ALTER TABLE withdrawal.withdrawal_transactions ADD CONSTRAINT wt_channel_check CHECK (channel IN ('mobile_money','bank_transfer'))");

        DB::statement("CREATE UNIQUE INDEX wt_idempotency_key_idx ON withdrawal.withdrawal_transactions (idempotency_key) WHERE idempotency_key IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX wt_transaction_reference_idx ON withdrawal.withdrawal_transactions (transaction_reference)");
        DB::statement("CREATE INDEX wt_customer_history_idx ON withdrawal.withdrawal_transactions (customer_id, created_at DESC)");
        DB::statement("CREATE INDEX wt_customer_status_idx ON withdrawal.withdrawal_transactions (customer_id, status, created_at DESC) WHERE status IN ('pending','processing','completed')");
        DB::statement("CREATE INDEX wt_provider_reference_idx ON withdrawal.withdrawal_transactions (provider_reference_id)");
        DB::statement("CREATE INDEX wt_stale_processing_idx ON withdrawal.withdrawal_transactions (status, created_at ASC) WHERE status = 'processing'");
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('withdrawal.withdrawal_transactions');
    }
};
