<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P20: Allow module_config drafts (which have no parent provider).
 *
 * Changes:
 *   1. Make provider_id nullable (module config drafts have no provider).
 *   2. Drop and re-add chk_payment_config_drafts_change_type to include 'module_config'.
 *   3. Drop and re-create idx_payment_config_drafts_provider_status (uses nullable provider_id).
 */
return new class extends Migration
{
    protected $connection = 'payment';

    public function up(): void
    {
        // 1. Make provider_id nullable
        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_config_change_drafts
             ALTER COLUMN provider_id DROP NOT NULL'
        );

        // 2. Drop the existing change_type CHECK constraint and re-add with module_config included
        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_config_change_drafts
             DROP CONSTRAINT IF EXISTS chk_payment_config_drafts_change_type'
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_config_change_drafts
             ADD CONSTRAINT chk_payment_config_drafts_change_type
             CHECK (change_type IN ('high_risk_update','credential_rotation','module_config'))"
        );

        // 3. Drop and re-create the provider_status index
        // (the original index was created without NULLS LAST; drop it and recreate as a partial
        //  or standard index that handles nullable provider_id gracefully)
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.idx_payment_config_drafts_provider_status'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX idx_payment_config_drafts_provider_status
             ON payment.payment_config_change_drafts (provider_id, status)'
        );
    }

    public function down(): void
    {
        // Reverse provider_id to NOT NULL (will fail if null rows exist)
        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_config_change_drafts
             ALTER COLUMN provider_id SET NOT NULL'
        );

        // Restore original change_type constraint
        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_config_change_drafts
             DROP CONSTRAINT IF EXISTS chk_payment_config_drafts_change_type'
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_config_change_drafts
             ADD CONSTRAINT chk_payment_config_drafts_change_type
             CHECK (change_type IN ('high_risk_update','credential_rotation'))"
        );

        // Restore original index
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.idx_payment_config_drafts_provider_status'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX idx_payment_config_drafts_provider_status
             ON payment.payment_config_change_drafts (provider_id, status)'
        );
    }
};
