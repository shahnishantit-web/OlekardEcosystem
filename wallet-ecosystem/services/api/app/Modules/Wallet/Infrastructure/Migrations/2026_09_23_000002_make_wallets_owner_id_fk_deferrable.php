<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection('platform')->statement(
            'ALTER TABLE wallet.wallets DROP CONSTRAINT IF EXISTS wallets_owner_id_fk'
        );
        DB::connection('platform')->statement(
            'ALTER TABLE wallet.wallets ADD CONSTRAINT wallets_owner_id_fk
             FOREIGN KEY (owner_id) REFERENCES identity.users(id)
             DEFERRABLE INITIALLY DEFERRED'
        );
    }

    public function down(): void
    {
        DB::connection('platform')->statement(
            'ALTER TABLE wallet.wallets DROP CONSTRAINT IF EXISTS wallets_owner_id_fk'
        );
        DB::connection('platform')->statement(
            'ALTER TABLE wallet.wallets ADD CONSTRAINT wallets_owner_id_fk
             FOREIGN KEY (owner_id) REFERENCES identity.users(id)'
        );
    }
};
