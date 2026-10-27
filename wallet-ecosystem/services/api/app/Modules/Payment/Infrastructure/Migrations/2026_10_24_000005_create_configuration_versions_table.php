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
            "CREATE TABLE IF NOT EXISTS payment.configuration_versions (
                id UUID PRIMARY KEY,
                account_id UUID NOT NULL REFERENCES payment.payment_provider_accounts(id) ON DELETE CASCADE,
                version_number INTEGER NOT NULL,
                snapshot JSONB NOT NULL,
                activated_at TIMESTAMPTZ NOT NULL,
                activated_by_actor_id UUID NOT NULL,
                superseded_at TIMESTAMPTZ NULL,
                created_at TIMESTAMPTZ DEFAULT NOW(),
                CONSTRAINT uq_configuration_version_per_account UNIQUE (account_id, version_number)
            )"
        );

        DB::connection('payment')->statement(
            'CREATE INDEX IF NOT EXISTS idx_configuration_versions_account
             ON payment.configuration_versions (account_id, activated_at DESC)'
        );

        DB::connection('payment')->statement(
            'ALTER TABLE payment.api_operation_configs
             ADD CONSTRAINT IF NOT EXISTS fk_api_operation_configs_version
             FOREIGN KEY (config_version_id) REFERENCES payment.configuration_versions(id) ON DELETE SET NULL'
        );

        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_provider_accounts
             ADD CONSTRAINT IF NOT EXISTS fk_active_config_version
             FOREIGN KEY (active_config_version_id) REFERENCES payment.configuration_versions(id) ON DELETE SET NULL'
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_provider_accounts
             DROP CONSTRAINT IF EXISTS fk_active_config_version'
        );

        DB::connection('payment')->statement(
            'ALTER TABLE payment.api_operation_configs
             DROP CONSTRAINT IF EXISTS fk_api_operation_configs_version'
        );

        DB::connection('payment')->statement(
            'DROP TABLE IF EXISTS payment.configuration_versions'
        );
    }
};
