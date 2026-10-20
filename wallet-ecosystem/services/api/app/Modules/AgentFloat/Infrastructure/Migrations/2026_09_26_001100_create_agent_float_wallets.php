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
        Schema::connection('platform')->create('agent.float_wallets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('agent_id')->unique();
            // wallet_id is a SOFT cross-schema reference — wallet.wallets uses 'wallet' connection;
            // agent.float_wallets uses 'platform' connection; no DB-level FK is possible.
            $table->uuid('wallet_id');
            $table->string('ledger_account_reference', 128);
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            'ALTER TABLE agent.float_wallets ADD CONSTRAINT float_wallets_agent_id_fk
             FOREIGN KEY (agent_id) REFERENCES agent.agents(id)'
        );

        DB::connection('platform')->statement(
            'CREATE UNIQUE INDEX float_wallets_agent_id_unique_idx ON agent.float_wallets (agent_id)'
        );

        DB::connection('platform')->statement(
            'CREATE INDEX float_wallets_wallet_id_idx ON agent.float_wallets (wallet_id)'
        );

        DB::connection('platform')->statement(
            'CREATE UNIQUE INDEX float_wallets_ledger_ref_unique_idx ON agent.float_wallets (ledger_account_reference)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('agent.float_wallets');
    }
};
