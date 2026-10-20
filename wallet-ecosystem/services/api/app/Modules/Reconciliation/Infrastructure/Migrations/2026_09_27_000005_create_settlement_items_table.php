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
        Schema::connection('reconciliation')->create('reconciliation.settlement_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('settlement_batch_id');
            $table->uuid('reconciliation_item_id');
            $table->decimal('amount', 18, 4);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['settlement_batch_id', 'reconciliation_item_id']);

            $table->foreign('settlement_batch_id')
                  ->references('id')
                  ->on('reconciliation.settlement_batches')
                  ->onDelete('cascade');
            $table->foreign('reconciliation_item_id')
                  ->references('id')
                  ->on('reconciliation.reconciliation_items')
                  ->onDelete('cascade');
        });

        DB::connection('reconciliation')->statement('
            CREATE INDEX idx_settlement_item_batch
            ON reconciliation.settlement_items (settlement_batch_id)
        ');
    }

    public function down(): void
    {
        Schema::connection('reconciliation')->dropIfExists('reconciliation.settlement_items');
    }
};
