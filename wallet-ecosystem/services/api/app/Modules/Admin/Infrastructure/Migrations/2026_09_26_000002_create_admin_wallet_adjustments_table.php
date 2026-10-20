<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'admin';

    public function up(): void
    {
        Schema::connection('admin')->create('admin.admin_wallet_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('idempotency_key', 128)->unique();
            $table->uuid('wallet_id');
            $table->string('adjustment_type', 10); // credit | debit
            $table->decimal('amount', 18, 4);
            $table->string('currency', 3)->default('BWP');
            $table->text('reason');
            $table->string('status', 20)->default('pending_approval');
            $table->uuid('maker_id');
            $table->uuid('checker_id')->nullable();
            $table->uuid('ledger_transaction_id')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampsTz();
        });

        DB::connection('admin')->statement("
            ALTER TABLE admin.admin_wallet_adjustments
            ADD CONSTRAINT chk_adjustment_status
            CHECK (status IN ('pending_approval','approved','rejected','posted'))
        ");
        DB::connection('admin')->statement("
            ALTER TABLE admin.admin_wallet_adjustments
            ADD CONSTRAINT chk_maker_checker_diff
            CHECK (checker_id IS NULL OR maker_id != checker_id)
        ");
        DB::connection('admin')->statement('
            ALTER TABLE admin.admin_wallet_adjustments
            ADD CONSTRAINT chk_amount_positive CHECK (amount > 0)
        ');
        DB::connection('admin')->statement('
            CREATE INDEX idx_adj_wallet_status ON admin.admin_wallet_adjustments (wallet_id, status, created_at DESC)
        ');
        DB::connection('admin')->statement('
            CREATE INDEX idx_adj_maker ON admin.admin_wallet_adjustments (maker_id, created_at DESC)
        ');
        DB::connection('admin')->statement('
            CREATE INDEX idx_adj_status ON admin.admin_wallet_adjustments (status, created_at DESC)
        ');
    }

    public function down(): void
    {
        Schema::connection('admin')->dropIfExists('admin.admin_wallet_adjustments');
    }
};
