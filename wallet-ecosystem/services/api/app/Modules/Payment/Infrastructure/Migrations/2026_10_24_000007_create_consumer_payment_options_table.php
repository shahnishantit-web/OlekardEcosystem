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
            "CREATE TABLE IF NOT EXISTS payment.consumer_payment_options (
                id UUID PRIMARY KEY,
                account_id UUID NOT NULL REFERENCES payment.payment_provider_accounts(id) ON DELETE CASCADE,
                display_label VARCHAR(255) NOT NULL,
                payment_method VARCHAR(64) NOT NULL,
                display_order SMALLINT NOT NULL DEFAULT 0,
                supported_purpose_types JSONB NOT NULL DEFAULT '[]',
                country_restrictions JSONB NOT NULL DEFAULT '[]',
                is_active BOOLEAN NOT NULL DEFAULT false,
                created_at TIMESTAMPTZ DEFAULT NOW(),
                updated_at TIMESTAMPTZ DEFAULT NOW(),
                CONSTRAINT chk_consumer_payment_method CHECK (payment_method IN ('bank_redirect','bank_checkout','direct_debit','card','wallet'))
            )"
        );

        DB::connection('payment')->statement(
            'CREATE INDEX IF NOT EXISTS idx_consumer_payment_options_account_active
             ON payment.consumer_payment_options (account_id, is_active)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX IF NOT EXISTS idx_consumer_payment_options_method_active
             ON payment.consumer_payment_options (payment_method, is_active)'
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'DROP TABLE IF EXISTS payment.consumer_payment_options'
        );
    }
};
