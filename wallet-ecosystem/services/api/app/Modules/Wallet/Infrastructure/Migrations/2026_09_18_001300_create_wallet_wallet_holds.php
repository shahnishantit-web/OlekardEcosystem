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
        Schema::connection('wallet')->create('wallet.wallet_holds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('pocket_id');
            $table->bigInteger('amount')->unsigned();
            $table->string('currency_code', 10);
            $table->string('status', 20)->default('active');
            $table->string('transaction_reference', 255)->nullable();
            $table->text('description')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->uuid('created_by');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::connection('wallet')->statement(
            "ALTER TABLE wallet.wallet_holds ADD CONSTRAINT wallet_holds_amount_positive CHECK (amount > 0)"
        );

        DB::connection('wallet')->statement(
            "ALTER TABLE wallet.wallet_holds ADD CONSTRAINT wallet_holds_status_check CHECK (status IN ('active','released','expired'))"
        );

        DB::connection('wallet')->statement(
            'ALTER TABLE wallet.wallet_holds ADD CONSTRAINT wallet_holds_pocket_id_fk FOREIGN KEY (pocket_id) REFERENCES wallet.currency_pockets(id)'
        );

        // Sum of active holds per pocket (held_amount computation)
        DB::connection('wallet')->statement(
            "CREATE INDEX wallet_holds_pocket_id_active_idx ON wallet.wallet_holds (pocket_id, status) WHERE status = 'active'"
        );

        // Expiry job: find expired holds
        DB::connection('wallet')->statement(
            "CREATE INDEX wallet_holds_expires_at_active_idx ON wallet.wallet_holds (expires_at, status) WHERE status = 'active' AND expires_at IS NOT NULL"
        );
    }

    public function down(): void
    {
        Schema::connection('wallet')->dropIfExists('wallet.wallet_holds');
    }
};
