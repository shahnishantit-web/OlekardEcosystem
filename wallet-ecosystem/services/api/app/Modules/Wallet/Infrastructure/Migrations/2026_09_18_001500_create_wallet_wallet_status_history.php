<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'wallet';

    public function up(): void
    {
        Schema::connection('wallet')->create('wallet.wallet_status_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('reason')->nullable();
            $table->uuid('actor_id');
            $table->string('correlation_id', 255)->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
        });

        DB::connection('wallet')->statement(
            'ALTER TABLE wallet.wallet_status_history ADD CONSTRAINT wallet_status_history_wallet_id_fk FOREIGN KEY (wallet_id) REFERENCES wallet.wallets(id)'
        );

        // Chronological pagination
        DB::connection('wallet')->statement(
            'CREATE INDEX wallet_status_history_wallet_id_occurred_at_idx ON wallet.wallet_status_history (wallet_id, occurred_at ASC)'
        );
    }

    public function down(): void
    {
        Schema::connection('wallet')->dropIfExists('wallet.wallet_status_history');
    }
};
