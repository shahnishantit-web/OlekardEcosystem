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
            "CREATE TABLE IF NOT EXISTS payment.api_operation_configs (
                id UUID PRIMARY KEY,
                account_id UUID NOT NULL REFERENCES payment.payment_provider_accounts(id) ON DELETE CASCADE,
                config_version_id UUID NULL,
                operation_type VARCHAR(32) NOT NULL,
                base_url VARCHAR(500) NOT NULL,
                endpoint_path VARCHAR(500) NOT NULL,
                http_method VARCHAR(10) NOT NULL,
                content_type VARCHAR(64) NOT NULL DEFAULT 'application/json',
                non_secret_headers JSONB NOT NULL DEFAULT '{}',
                auth_profile VARCHAR(64) NOT NULL,
                secret_key_references JSONB NOT NULL DEFAULT '{}',
                request_field_mappings JSONB NOT NULL DEFAULT '[]',
                response_field_mappings JSONB NOT NULL DEFAULT '[]',
                provider_txn_ref_mapping VARCHAR(255) NULL,
                idempotency_ref_mapping VARCHAR(255) NULL,
                amount_format VARCHAR(64) NOT NULL DEFAULT 'minor_integer',
                currency_format VARCHAR(32) NOT NULL DEFAULT 'iso4217_upper',
                success_status_values JSONB NOT NULL DEFAULT '[]',
                pending_status_values JSONB NOT NULL DEFAULT '[]',
                failure_status_values JSONB NOT NULL DEFAULT '[]',
                timeout_ms INTEGER NOT NULL DEFAULT 30000,
                max_retries SMALLINT NOT NULL DEFAULT 2,
                retry_backoff_ms INTEGER NOT NULL DEFAULT 1000,
                callback_verification_profile VARCHAR(64) NULL,
                api_version VARCHAR(32) NULL,
                is_payout BOOLEAN NOT NULL DEFAULT false,
                created_at TIMESTAMPTZ DEFAULT NOW(),
                updated_at TIMESTAMPTZ DEFAULT NOW(),
                CONSTRAINT chk_operation_type CHECK (operation_type IN ('initiate','query','cancel','full_refund','partial_refund','callback','reconciliation')),
                CONSTRAINT chk_http_method CHECK (http_method IN ('GET','POST','PUT','PATCH')),
                CONSTRAINT chk_is_payout_false CHECK (is_payout = false)
            )"
        );

        DB::connection('payment')->statement(
            'CREATE INDEX IF NOT EXISTS idx_api_operation_configs_account
             ON payment.api_operation_configs (account_id, operation_type)'
        );

        DB::connection('payment')->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_api_operation_config_per_version
             ON payment.api_operation_configs (account_id, operation_type, config_version_id)
             WHERE config_version_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'DROP TABLE IF EXISTS payment.api_operation_configs'
        );
    }
};
