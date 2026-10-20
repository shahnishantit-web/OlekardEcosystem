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
            CREATE TABLE IF NOT EXISTS platform.configuration_approvals (
                id                UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                change_request_id UUID        NOT NULL REFERENCES platform.configuration_change_requests(id) ON DELETE CASCADE,
                checker_id        UUID        NOT NULL,
                decision          VARCHAR(10) NOT NULL,
                comments          TEXT,
                idempotency_key   VARCHAR(255) NOT NULL,
                decided_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT chk_config_approval_decision CHECK (decision IN ('approved','rejected'))
            )
        ");

        DB::connection('platform')->statement("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_config_approvals_idempotency
                ON platform.configuration_approvals (idempotency_key)
        ");

        DB::connection('platform')->statement("
            CREATE INDEX IF NOT EXISTS idx_config_approvals_change_request
                ON platform.configuration_approvals (change_request_id)
        ");
    }

    public function down(): void
    {
        DB::connection('platform')->statement(
            'DROP TABLE IF EXISTS platform.configuration_approvals CASCADE'
        );
    }
};
