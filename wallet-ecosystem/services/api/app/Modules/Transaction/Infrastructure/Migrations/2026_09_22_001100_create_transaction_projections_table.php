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
        Schema::connection('platform')->create('transaction.transaction_projections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference', 40);
            $table->string('type', 30);
            $table->string('status', 30);
            $table->bigInteger('amount');
            $table->char('currency_code', 3);
            $table->string('direction', 10);
            $table->uuid('wallet_id');
            $table->uuid('customer_id');
            $table->string('description', 500)->nullable();
            $table->string('source_module', 30);
            $table->jsonb('metadata')->nullable();
            $table->string('idempotency_key', 255)->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->string('ledger_journal_id', 100)->nullable();
            $table->uuid('parent_transaction_id')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE transaction.transaction_projections ADD CONSTRAINT tp_amount_check CHECK (amount > 0)");
        DB::statement("ALTER TABLE transaction.transaction_projections ADD CONSTRAINT tp_type_check CHECK (type IN ('funding','transfer','withdrawal','refund'))");
        DB::statement("ALTER TABLE transaction.transaction_projections ADD CONSTRAINT tp_direction_check CHECK (direction IN ('debit','credit'))");
        DB::statement("ALTER TABLE transaction.transaction_projections ADD CONSTRAINT tp_parent_fk FOREIGN KEY (parent_transaction_id) REFERENCES transaction.transaction_projections(id) DEFERRABLE INITIALLY DEFERRED");

        DB::statement("CREATE UNIQUE INDEX tp_reference_idx ON transaction.transaction_projections (reference)");
        DB::statement("CREATE INDEX tp_customer_idx ON transaction.transaction_projections (customer_id)");
        DB::statement("CREATE INDEX tp_customer_type_idx ON transaction.transaction_projections (customer_id, type)");
        DB::statement("CREATE INDEX tp_customer_status_idx ON transaction.transaction_projections (customer_id, status)");
        DB::statement("CREATE INDEX tp_customer_created_idx ON transaction.transaction_projections (customer_id, created_at DESC)");
        DB::statement("CREATE INDEX tp_fts_idx ON transaction.transaction_projections USING gin(to_tsvector('english', reference || ' ' || coalesce(description, '')))");
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('transaction.transaction_projections');
    }
};
