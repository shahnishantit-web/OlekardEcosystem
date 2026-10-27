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
            "CREATE TABLE IF NOT EXISTS payment.integration_profiles (
                id UUID PRIMARY KEY,
                profile_identifier VARCHAR(128) NOT NULL,
                version VARCHAR(32) NOT NULL,
                display_name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                supported_auth_mechanisms JSONB NOT NULL DEFAULT '[]',
                supported_capabilities JSONB NOT NULL DEFAULT '[]',
                config_schema JSONB NOT NULL DEFAULT '{}',
                is_active BOOLEAN NOT NULL DEFAULT true,
                notes TEXT NULL,
                created_at TIMESTAMPTZ DEFAULT NOW(),
                updated_at TIMESTAMPTZ DEFAULT NOW(),
                CONSTRAINT uq_integration_profile_identifier_version UNIQUE (profile_identifier, version)
            )"
        );

        DB::connection('payment')->statement(
            'CREATE INDEX IF NOT EXISTS idx_integration_profiles_is_active
             ON payment.integration_profiles (is_active)'
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'DROP TABLE IF EXISTS payment.integration_profiles'
        );
    }
};
