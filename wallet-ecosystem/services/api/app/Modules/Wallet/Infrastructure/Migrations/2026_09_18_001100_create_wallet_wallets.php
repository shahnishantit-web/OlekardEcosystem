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
        Schema::connection('wallet')->create('wallet.wallets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('wallet_reference', 32)->unique();
            $table->uuid('owner_id');
            $table->string('status', 20)->default('pending');
            $table->string('idempotency_key', 255)->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->uuid('created_by');
            $table->timestampsTz();
        });

        DB::connection('wallet')->statement(
            "ALTER TABLE wallet.wallets ADD CONSTRAINT wallets_status_check CHECK (status IN ('pending','active','frozen','suspended','restricted','closed'))"
        );

        // Prevent duplicate active wallets per user (partial unique index)
        DB::connection('wallet')->statement(
            "CREATE UNIQUE INDEX wallets_owner_id_active_unique ON wallet.wallets (owner_id) WHERE status <> 'closed'"
        );

        DB::connection('wallet')->statement(
            'CREATE UNIQUE INDEX wallets_idempotency_key_unique ON wallet.wallets (idempotency_key) WHERE idempotency_key IS NOT NULL'
        );

        // Cross-schema FK: owner_id references identity.users.id
        DB::connection('wallet')->statement(
            'ALTER TABLE wallet.wallets ADD CONSTRAINT wallets_owner_id_fk FOREIGN KEY (owner_id) REFERENCES identity.users(id)'
        );
    }

    public function down(): void
    {
        Schema::connection('wallet')->dropIfExists('wallet.wallets');
    }
};
