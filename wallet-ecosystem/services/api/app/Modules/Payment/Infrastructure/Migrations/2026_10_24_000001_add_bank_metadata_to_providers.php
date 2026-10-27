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
            "ALTER TABLE payment.payment_providers
             ADD COLUMN IF NOT EXISTS bank_lifecycle_status VARCHAR(32) NOT NULL DEFAULT 'draft',
             ADD COLUMN IF NOT EXISTS supported_countries JSONB NOT NULL DEFAULT '[]',
             ADD COLUMN IF NOT EXISTS organisation_ownership UUID NULL,
             ADD COLUMN IF NOT EXISTS description TEXT NULL,
             ADD COLUMN IF NOT EXISTS logo_reference VARCHAR(500) NULL,
             ADD COLUMN IF NOT EXISTS metadata_version INTEGER NOT NULL DEFAULT 1"
        );

        if (! DB::connection('payment')->selectOne(
            "SELECT 1 FROM pg_constraint WHERE conrelid = 'payment.payment_providers'::regclass AND conname = 'chk_bank_lifecycle_status'"
        )) {
            DB::connection('payment')->statement(
                "ALTER TABLE payment.payment_providers
                 ADD CONSTRAINT chk_bank_lifecycle_status
                 CHECK (bank_lifecycle_status IN ('draft','active','suspended','retired'))"
            );
        }

        DB::connection('payment')->statement(
            'CREATE INDEX IF NOT EXISTS idx_payment_providers_bank_lifecycle
             ON payment.payment_providers (bank_lifecycle_status)'
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.idx_payment_providers_bank_lifecycle'
        );

        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_providers
             DROP CONSTRAINT IF EXISTS chk_bank_lifecycle_status'
        );

        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_providers
             DROP COLUMN IF EXISTS bank_lifecycle_status,
             DROP COLUMN IF EXISTS supported_countries,
             DROP COLUMN IF EXISTS organisation_ownership,
             DROP COLUMN IF EXISTS description,
             DROP COLUMN IF EXISTS logo_reference,
             DROP COLUMN IF EXISTS metadata_version'
        );
    }
};
