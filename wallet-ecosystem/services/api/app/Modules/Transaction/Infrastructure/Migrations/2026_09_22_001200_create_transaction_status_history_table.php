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
        Schema::connection('platform')->create('transaction.transaction_status_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id');
            $table->string('previous_status', 30)->nullable();
            $table->string('new_status', 30);
            $table->uuid('actor_id')->nullable();
            $table->string('actor_type', 50)->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestampTz('occurred_at');
        });

        DB::statement("ALTER TABLE transaction.transaction_status_history ADD CONSTRAINT tsh_transaction_fk FOREIGN KEY (transaction_id) REFERENCES transaction.transaction_projections(id) ON DELETE CASCADE");
        DB::statement("CREATE INDEX tsh_transaction_occurred_idx ON transaction.transaction_status_history (transaction_id, occurred_at ASC)");
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('transaction.transaction_status_history');
    }
};
