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
            "ALTER TABLE payment.payment_provider_accounts
             ADD COLUMN IF NOT EXISTS account_lifecycle_status VARCHAR(32) NOT NULL DEFAULT 'draft',
             ADD COLUMN IF NOT EXISTS submitted_by_actor_id UUID NULL,
             ADD COLUMN IF NOT EXISTS approved_by_actor_id UUID NULL,
             ADD COLUMN IF NOT EXISTS approved_at TIMESTAMPTZ NULL,
             ADD COLUMN IF NOT EXISTS activated_at TIMESTAMPTZ NULL,
             ADD COLUMN IF NOT EXISTS suspended_at TIMESTAMPTZ NULL,
             ADD COLUMN IF NOT EXISTS retired_at TIMESTAMPTZ NULL,
             ADD COLUMN IF NOT EXISTS rejection_reason TEXT NULL,
             ADD COLUMN IF NOT EXISTS active_config_version_id UUID NULL,
             ADD COLUMN IF NOT EXISTS display_label VARCHAR(255) NULL,
             ADD COLUMN IF NOT EXISTS display_sort_order SMALLINT NOT NULL DEFAULT 0"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_provider_accounts
             ADD CONSTRAINT IF NOT EXISTS chk_account_lifecycle_status
             CHECK (account_lifecycle_status IN ('draft','validated','sandbox_testing','evidence_review','approved','active','suspended','retired'))"
        );

        DB::connection('payment')->statement(
            'CREATE INDEX IF NOT EXISTS idx_provider_accounts_lifecycle
             ON payment.payment_provider_accounts (account_lifecycle_status)'
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.idx_provider_accounts_lifecycle'
        );

        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_provider_accounts
             DROP CONSTRAINT IF EXISTS chk_account_lifecycle_status'
        );

        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_provider_accounts
             DROP COLUMN IF EXISTS account_lifecycle_status,
             DROP COLUMN IF EXISTS submitted_by_actor_id,
             DROP COLUMN IF EXISTS approved_by_actor_id,
             DROP COLUMN IF EXISTS approved_at,
             DROP COLUMN IF EXISTS activated_at,
             DROP COLUMN IF EXISTS suspended_at,
             DROP COLUMN IF EXISTS retired_at,
             DROP COLUMN IF EXISTS rejection_reason,
             DROP COLUMN IF EXISTS active_config_version_id,
             DROP COLUMN IF EXISTS display_label,
             DROP COLUMN IF EXISTS display_sort_order'
        );
    }
};
