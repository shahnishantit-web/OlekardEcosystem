<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'payment';

    public function up(): void
    {
        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_provider_accounts
             ADD COLUMN IF NOT EXISTS config_version INTEGER NOT NULL DEFAULT 1,
             ADD COLUMN IF NOT EXISTS enabled_purpose_types JSONB NOT NULL DEFAULT \'[]\',
             ADD COLUMN IF NOT EXISTS display_description VARCHAR(255) NULL,
             ADD COLUMN IF NOT EXISTS display_icon_key VARCHAR(128) NULL,
             ADD COLUMN IF NOT EXISTS display_badge VARCHAR(64) NULL'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX IF NOT EXISTS provider_accounts_config_version_idx
             ON payment.payment_provider_accounts (config_version)'
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_provider_accounts
             DROP COLUMN IF EXISTS config_version,
             DROP COLUMN IF EXISTS enabled_purpose_types,
             DROP COLUMN IF EXISTS display_description,
             DROP COLUMN IF EXISTS display_icon_key,
             DROP COLUMN IF EXISTS display_badge'
        );

        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.provider_accounts_config_version_idx'
        );
    }
};
