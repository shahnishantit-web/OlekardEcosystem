<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection('platform')->statement("
            CREATE TABLE IF NOT EXISTS platform.configuration_versions (
                id                UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                config_key        VARCHAR(255) NOT NULL,
                domain            VARCHAR(50)  NOT NULL,
                event_type        VARCHAR(20)  NOT NULL,
                value             JSONB,
                actor_id          UUID,
                change_request_id UUID        REFERENCES platform.configuration_change_requests(id) ON DELETE SET NULL,
                notes             TEXT,
                occurred_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT chk_config_version_event_type
                    CHECK (event_type IN ('submitted','approved','rejected','withdrawn','activated','failed'))
            )
        ");

        DB::connection('platform')->statement("
            CREATE INDEX IF NOT EXISTS idx_config_versions_config_key_occurred
                ON platform.configuration_versions (config_key, occurred_at DESC)
        ");

        DB::connection('platform')->statement("
            CREATE INDEX IF NOT EXISTS idx_config_versions_change_request
                ON platform.configuration_versions (change_request_id)
        ");

        // Immutable audit table — no mutations permitted after insert (FR-016)
        DB::connection('platform')->statement(
            'REVOKE UPDATE, DELETE ON platform.configuration_versions FROM PUBLIC'
        );
    }

    public function down(): void
    {
        // Re-grant before drop to avoid permission errors on repeated down/up
        DB::connection('platform')->statement(
            'GRANT UPDATE, DELETE ON platform.configuration_versions TO PUBLIC'
        );

        DB::connection('platform')->statement(
            'DROP TABLE IF EXISTS platform.configuration_versions CASCADE'
        );
    }
};
