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
            "CREATE TABLE IF NOT EXISTS payment.certification_evidence (
                id UUID PRIMARY KEY,
                account_id UUID NOT NULL REFERENCES payment.payment_provider_accounts(id) ON DELETE CASCADE,
                config_version_id UUID NULL REFERENCES payment.configuration_versions(id) ON DELETE SET NULL,
                evidence_type VARCHAR(64) NOT NULL,
                title VARCHAR(255) NOT NULL,
                notes TEXT NULL,
                file_reference VARCHAR(500) NULL,
                test_run_result JSONB NULL,
                submitted_by_actor_id UUID NOT NULL,
                created_at TIMESTAMPTZ DEFAULT NOW(),
                CONSTRAINT chk_evidence_type CHECK (evidence_type IN ('test_run','documentation','merchant_ownership','sandbox_access','refund_evidence','reconciliation_evidence','other'))
            )"
        );

        DB::connection('payment')->statement(
            'CREATE INDEX IF NOT EXISTS idx_certification_evidence_account_type
             ON payment.certification_evidence (account_id, evidence_type)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX IF NOT EXISTS idx_certification_evidence_version
             ON payment.certification_evidence (config_version_id) WHERE config_version_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'DROP TABLE IF EXISTS payment.certification_evidence'
        );
    }
};
