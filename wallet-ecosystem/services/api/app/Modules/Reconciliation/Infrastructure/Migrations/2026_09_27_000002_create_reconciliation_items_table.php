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
        Schema::connection('reconciliation')->create('reconciliation.reconciliation_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('batch_id');
            $table->string('item_type', 30);
            $table->string('provider_reference', 256);
            $table->uuid('internal_transaction_id')->nullable();
            $table->decimal('amount', 18, 4);
            $table->char('currency_code', 3)->default('BWP');
            $table->date('transaction_date');
            $table->string('status', 20)->default('pending');
            $table->smallInteger('match_confidence')->default(0);
            $table->timestampTz('matched_at')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->foreign('batch_id')
                  ->references('id')
                  ->on('reconciliation.reconciliation_batches')
                  ->onDelete('cascade');
        });

        DB::connection('reconciliation')->statement("
            ALTER TABLE reconciliation.reconciliation_items
            ADD CONSTRAINT chk_item_status
            CHECK (status IN ('pending','matched','unmatched','exception'))
        ");
        DB::connection('reconciliation')->statement("
            ALTER TABLE reconciliation.reconciliation_items
            ADD CONSTRAINT chk_item_type
            CHECK (item_type IN ('provider_transaction','bank_transaction'))
        ");
        DB::connection('reconciliation')->statement("
            ALTER TABLE reconciliation.reconciliation_items
            ADD CONSTRAINT chk_item_amount_nonzero CHECK (amount <> 0)
        ");
        DB::connection('reconciliation')->statement("
            ALTER TABLE reconciliation.reconciliation_items
            ADD CONSTRAINT chk_match_confidence CHECK (match_confidence >= 0 AND match_confidence <= 100)
        ");
        DB::connection('reconciliation')->statement('
            CREATE INDEX idx_item_batch_status
            ON reconciliation.reconciliation_items (batch_id, status)
        ');
        DB::connection('reconciliation')->statement('
            CREATE INDEX idx_item_provider_ref
            ON reconciliation.reconciliation_items (provider_reference, currency_code)
        ');
        DB::connection('reconciliation')->statement('
            CREATE INDEX idx_item_internal_tx
            ON reconciliation.reconciliation_items (internal_transaction_id)
        ');
        DB::connection('reconciliation')->statement('
            CREATE INDEX idx_item_status_created
            ON reconciliation.reconciliation_items (status, created_at DESC)
        ');
    }

    public function down(): void
    {
        Schema::connection('reconciliation')->dropIfExists('reconciliation.reconciliation_items');
    }
};
