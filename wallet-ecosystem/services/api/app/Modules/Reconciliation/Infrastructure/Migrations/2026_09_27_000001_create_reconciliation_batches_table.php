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
        DB::connection('reconciliation')->statement('CREATE SCHEMA IF NOT EXISTS reconciliation');

        Schema::connection('reconciliation')->create('reconciliation.reconciliation_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('idempotency_key', 128)->unique();
            $table->string('source_type', 30);
            $table->string('source_reference', 256);
            $table->date('batch_date');
            $table->string('provider_code', 50)->nullable();
            $table->string('status', 20)->default('draft');
            $table->integer('total_count')->default(0);
            $table->integer('matched_count')->default(0);
            $table->integer('unmatched_count')->default(0);
            $table->integer('exception_count')->default(0);
            $table->string('file_path', 512)->nullable();
            $table->uuid('submitted_by');
            $table->timestampTz('processed_at')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
        });

        DB::connection('reconciliation')->statement("
            ALTER TABLE reconciliation.reconciliation_batches
            ADD CONSTRAINT chk_batch_status
            CHECK (status IN ('draft','processing','matched','completed','failed'))
        ");
        DB::connection('reconciliation')->statement("
            ALTER TABLE reconciliation.reconciliation_batches
            ADD CONSTRAINT chk_batch_source_type
            CHECK (source_type IN ('provider_file','provider_api','bank_statement'))
        ");
        DB::connection('reconciliation')->statement('
            CREATE UNIQUE INDEX idx_batch_source_unique
            ON reconciliation.reconciliation_batches (source_type, source_reference, batch_date)
        ');
        DB::connection('reconciliation')->statement('
            CREATE INDEX idx_batch_status_created
            ON reconciliation.reconciliation_batches (status, created_at DESC)
        ');
        DB::connection('reconciliation')->statement('
            CREATE INDEX idx_batch_submitted_by
            ON reconciliation.reconciliation_batches (submitted_by, created_at DESC)
        ');
    }

    public function down(): void
    {
        Schema::connection('reconciliation')->dropIfExists('reconciliation.reconciliation_batches');
    }
};
