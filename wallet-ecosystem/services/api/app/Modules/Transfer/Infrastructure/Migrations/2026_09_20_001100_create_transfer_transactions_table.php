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
        Schema::connection('platform')->create('transfer.transfer_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('transfer_reference', 20)->notNullable();
            $table->uuid('sender_customer_id')->notNullable();
            $table->uuid('sender_wallet_id')->notNullable();
            $table->string('sender_pocket_ledger_ref', 100)->notNullable();
            $table->uuid('recipient_customer_id')->notNullable();
            $table->uuid('recipient_wallet_id')->notNullable();
            $table->string('recipient_pocket_ledger_ref', 100)->notNullable();
            $table->char('currency_code', 3)->notNullable();
            $table->bigInteger('gross_amount')->notNullable();
            $table->bigInteger('fee_amount')->default(0)->notNullable();
            $table->bigInteger('net_amount')->notNullable();
            $table->integer('fee_rate_bps')->default(0)->notNullable();
            $table->string('status', 20)->default('pending')->notNullable();
            $table->string('failure_reason', 40)->nullable();
            $table->string('ledger_journal_id', 100)->nullable();
            $table->string('idempotency_key', 255)->nullable();
            $table->uuid('correlation_id')->notNullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE transfer.transfer_transactions ADD CONSTRAINT tt_gross_amount_check CHECK (gross_amount > 0)");
        DB::statement("ALTER TABLE transfer.transfer_transactions ADD CONSTRAINT tt_fee_amount_check CHECK (fee_amount >= 0)");
        DB::statement("ALTER TABLE transfer.transfer_transactions ADD CONSTRAINT tt_net_amount_check CHECK (net_amount > 0)");
        DB::statement("ALTER TABLE transfer.transfer_transactions ADD CONSTRAINT tt_amounts_check CHECK (net_amount = gross_amount - fee_amount)");
        DB::statement("ALTER TABLE transfer.transfer_transactions ADD CONSTRAINT tt_status_check CHECK (status IN ('pending','completed','failed','cancelled'))");
        DB::statement("ALTER TABLE transfer.transfer_transactions ADD CONSTRAINT tt_self_transfer_check CHECK (sender_customer_id <> recipient_customer_id)");

        DB::statement("CREATE UNIQUE INDEX tt_transfer_reference_idx ON transfer.transfer_transactions (transfer_reference)");
        DB::statement("CREATE UNIQUE INDEX tt_idempotency_key_idx ON transfer.transfer_transactions (idempotency_key) WHERE idempotency_key IS NOT NULL");
        DB::statement("CREATE INDEX tt_sender_idx ON transfer.transfer_transactions (sender_customer_id, created_at DESC)");
        DB::statement("CREATE INDEX tt_recipient_idx ON transfer.transfer_transactions (recipient_customer_id, created_at DESC)");
        DB::statement("CREATE INDEX tt_status_idx ON transfer.transfer_transactions (status, created_at DESC)");
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('transfer.transfer_transactions');
    }
};
