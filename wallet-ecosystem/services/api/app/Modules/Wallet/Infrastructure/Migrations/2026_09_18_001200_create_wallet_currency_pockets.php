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
        Schema::connection('wallet')->create('wallet.currency_pockets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id');
            $table->string('currency_code', 10);
            $table->string('ledger_account_reference', 255)->unique();
            $table->string('status', 20)->default('active');
            $table->uuid('created_by');
            $table->timestampsTz();
        });

        DB::connection('wallet')->statement(
            "ALTER TABLE wallet.currency_pockets ADD CONSTRAINT currency_pockets_status_check CHECK (status IN ('active','disabled'))"
        );

        DB::connection('wallet')->statement(
            'ALTER TABLE wallet.currency_pockets ADD CONSTRAINT currency_pockets_wallet_currency_unique UNIQUE (wallet_id, currency_code)'
        );

        DB::connection('wallet')->statement(
            'ALTER TABLE wallet.currency_pockets ADD CONSTRAINT currency_pockets_wallet_id_fk FOREIGN KEY (wallet_id) REFERENCES wallet.wallets(id)'
        );

        // SELECT FOR UPDATE target — also used for balance queries
        DB::connection('wallet')->statement(
            'CREATE INDEX currency_pockets_wallet_id_idx ON wallet.currency_pockets (wallet_id)'
        );

        DB::connection('wallet')->statement(
            'CREATE INDEX currency_pockets_currency_code_idx ON wallet.currency_pockets (currency_code)'
        );
    }

    public function down(): void
    {
        Schema::connection('wallet')->dropIfExists('wallet.currency_pockets');
    }
};
