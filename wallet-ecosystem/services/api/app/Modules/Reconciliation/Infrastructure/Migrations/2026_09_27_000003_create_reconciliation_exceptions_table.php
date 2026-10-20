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
        Schema::connection('reconciliation')->create('reconciliation.reconciliation_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('item_id')->unique();
            $table->uuid('batch_id');
            $table->string('exception_type', 30);
            $table->string('status', 20)->default('open');
            $table->uuid('opened_by');
            $table->uuid('assigned_to')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->text('resolution')->nullable();
            $table->string('resolution_type', 30)->nullable();
            $table->timestampTz('opened_at')->useCurrent();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->foreign('item_id')
                  ->references('id')
                  ->on('reconciliation.reconciliation_items')
                  ->onDelete('cascade');
            $table->foreign('batch_id')
                  ->references('id')
                  ->on('reconciliation.reconciliation_batches')
                  ->onDelete('cascade');
        });

        DB::connection('reconciliation')->statement("
            ALTER TABLE reconciliation.reconciliation_exceptions
            ADD CONSTRAINT chk_exception_status
            CHECK (status IN ('open','investigating','resolved','written_off'))
        ");
        DB::connection('reconciliation')->statement("
            ALTER TABLE reconciliation.reconciliation_exceptions
            ADD CONSTRAINT chk_exception_type
            CHECK (exception_type IN ('unmatched_provider','unmatched_bank','amount_mismatch','duplicate'))
        ");
        DB::connection('reconciliation')->statement('
            CREATE INDEX idx_exception_status_created
            ON reconciliation.reconciliation_exceptions (status, created_at DESC)
        ');
        DB::connection('reconciliation')->statement('
            CREATE INDEX idx_exception_batch_status
            ON reconciliation.reconciliation_exceptions (batch_id, status)
        ');
        DB::connection('reconciliation')->statement('
            CREATE INDEX idx_exception_assigned
            ON reconciliation.reconciliation_exceptions (assigned_to, status)
        ');
    }

    public function down(): void
    {
        Schema::connection('reconciliation')->dropIfExists('reconciliation.reconciliation_exceptions');
    }
};
