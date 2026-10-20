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
            CREATE TABLE IF NOT EXISTS platform.configuration_change_requests (
                id               UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                config_key       VARCHAR(255) NOT NULL,
                domain           VARCHAR(50)  NOT NULL,
                proposed_value   JSONB        NOT NULL,
                previous_value   JSONB,
                maker_id         UUID         NOT NULL,
                status           VARCHAR(30)  NOT NULL DEFAULT 'pending_approval',
                rejection_reason TEXT,
                idempotency_key  VARCHAR(255) NOT NULL,
                correlation_id   UUID,
                submitted_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                resolved_at      TIMESTAMPTZ,
                created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                CONSTRAINT chk_config_change_request_status
                    CHECK (status IN ('pending_approval','approved','rejected','withdrawn','failed'))
            )
        ");

        // One pending change per key — prevents concurrent submissions for same key (FR-007)
        DB::connection('platform')->statement("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_config_change_requests_pending_key
                ON platform.configuration_change_requests (config_key)
                WHERE status = 'pending_approval'
        ");

        DB::connection('platform')->statement("
            CREATE INDEX IF NOT EXISTS idx_config_change_requests_status_submitted
                ON platform.configuration_change_requests (status, submitted_at DESC)
        ");

        DB::connection('platform')->statement("
            CREATE INDEX IF NOT EXISTS idx_config_change_requests_maker
                ON platform.configuration_change_requests (maker_id)
        ");

        DB::connection('platform')->statement("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_config_change_requests_idempotency
                ON platform.configuration_change_requests (idempotency_key)
        ");

        // FK correlating activated values back to their originating change request (L2 finding)
        DB::connection('platform')->statement(
            'ALTER TABLE platform.configuration_values ADD COLUMN IF NOT EXISTS change_request_id UUID REFERENCES platform.configuration_change_requests(id) ON DELETE SET NULL'
        );
    }

    public function down(): void
    {
        DB::connection('platform')->statement(
            'ALTER TABLE platform.configuration_values DROP COLUMN IF EXISTS change_request_id'
        );

        DB::connection('platform')->statement(
            'DROP TABLE IF EXISTS platform.configuration_change_requests CASCADE'
        );
    }
};
