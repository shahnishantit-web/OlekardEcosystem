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
        Schema::connection('platform')->create('transaction.transaction_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id');
            $table->string('reference', 40);
            $table->bigInteger('amount');
            $table->char('currency_code', 3);
            $table->string('type', 30);
            $table->string('channel', 30)->nullable();
            $table->uuid('customer_id');
            $table->timestampTz('created_at');
        });

        DB::statement("ALTER TABLE transaction.transaction_receipts ADD CONSTRAINT tr_transaction_fk FOREIGN KEY (transaction_id) REFERENCES transaction.transaction_projections(id)");
        DB::statement("ALTER TABLE transaction.transaction_receipts ADD CONSTRAINT tr_amount_check CHECK (amount > 0)");
        DB::statement("CREATE UNIQUE INDEX tr_transaction_unique_idx ON transaction.transaction_receipts (transaction_id)");
        DB::statement("CREATE INDEX tr_customer_idx ON transaction.transaction_receipts (customer_id)");
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('transaction.transaction_receipts');
    }
};
