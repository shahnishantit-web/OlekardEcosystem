<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// FR-023: Each attempt MUST record the configuration version used at initiation time.
// This column is immutable after the attempt is committed (enforced at application layer).
return new class extends Migration
{
    protected $connection = 'payment';

    public function up(): void
    {
        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_attempts
             ADD COLUMN IF NOT EXISTS configuration_version_id UUID NULL
             REFERENCES payment.configuration_versions(id) ON DELETE SET NULL'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX IF NOT EXISTS idx_payment_attempts_config_version
             ON payment.payment_attempts (configuration_version_id)
             WHERE configuration_version_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.idx_payment_attempts_config_version'
        );

        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_attempts
             DROP COLUMN IF EXISTS configuration_version_id'
        );
    }
};
