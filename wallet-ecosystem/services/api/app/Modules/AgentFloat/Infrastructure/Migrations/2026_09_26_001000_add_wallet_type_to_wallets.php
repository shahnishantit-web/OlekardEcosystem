<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'wallet';

    public function up(): void
    {
        // Add wallet_type discriminator column
        DB::connection('wallet')->statement(
            "ALTER TABLE wallet.wallets ADD COLUMN wallet_type VARCHAR(20) NOT NULL DEFAULT 'customer'"
        );

        DB::connection('wallet')->statement(
            "ALTER TABLE wallet.wallets ADD CONSTRAINT wallets_wallet_type_check
             CHECK (wallet_type IN ('customer','agent_float'))"
        );

        // Drop the old partial unique index that only considered owner_id
        DB::connection('wallet')->statement(
            "DROP INDEX IF EXISTS wallets_owner_id_active_unique"
        );

        // Create new compound unique index: one active wallet per (owner, type)
        DB::connection('wallet')->statement(
            "CREATE UNIQUE INDEX wallets_owner_wallettype_active_unique
             ON wallet.wallets (owner_id, wallet_type) WHERE status <> 'closed'"
        );
    }

    public function down(): void
    {
        DB::connection('wallet')->statement(
            "DROP INDEX IF EXISTS wallets_owner_wallettype_active_unique"
        );

        DB::connection('wallet')->statement(
            "CREATE UNIQUE INDEX wallets_owner_id_active_unique
             ON wallet.wallets (owner_id) WHERE status <> 'closed'"
        );

        DB::connection('wallet')->statement(
            "ALTER TABLE wallet.wallets DROP CONSTRAINT IF EXISTS wallets_wallet_type_check"
        );

        DB::connection('wallet')->statement(
            "ALTER TABLE wallet.wallets DROP COLUMN IF EXISTS wallet_type"
        );
    }
};
