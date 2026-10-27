<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'payment';

    public function up(): void
    {
        DB::connection('payment')->statement(
            "CREATE TABLE IF NOT EXISTS payment.credential_rotation_log (
                id UUID PRIMARY KEY,
                account_id UUID NOT NULL REFERENCES payment.payment_provider_accounts(id) ON DELETE CASCADE,
                secret_role VARCHAR(128) NOT NULL,
                previous_key_name VARCHAR(500) NOT NULL,
                new_key_name VARCHAR(500) NOT NULL,
                rotated_by_actor_id UUID NOT NULL,
                rotation_reason TEXT NULL,
                effective_from TIMESTAMPTZ NOT NULL,
                created_at TIMESTAMPTZ DEFAULT NOW()
            )"
        );

        DB::connection('payment')->statement(
            'CREATE INDEX IF NOT EXISTS idx_credential_rotation_log_account_role
             ON payment.credential_rotation_log (account_id, secret_role, effective_from DESC)'
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'DROP TABLE IF EXISTS payment.credential_rotation_log'
        );
    }
};
