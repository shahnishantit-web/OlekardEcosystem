<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T065 — FR-015: Add previous_value, maker_id, checker_id columns to
 * platform.configuration_versions so each version record is self-contained.
 */
return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection('platform')->statement("
            ALTER TABLE platform.configuration_versions
                ADD COLUMN IF NOT EXISTS previous_value JSONB,
                ADD COLUMN IF NOT EXISTS maker_id       UUID,
                ADD COLUMN IF NOT EXISTS checker_id     UUID
        ");

        DB::connection('platform')->statement("
            CREATE INDEX IF NOT EXISTS idx_config_versions_maker_id
                ON platform.configuration_versions (maker_id)
            WHERE maker_id IS NOT NULL
        ");

        DB::connection('platform')->statement("
            CREATE INDEX IF NOT EXISTS idx_config_versions_checker_id
                ON platform.configuration_versions (checker_id)
            WHERE checker_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::connection('platform')->statement("
            ALTER TABLE platform.configuration_versions
                DROP COLUMN IF EXISTS previous_value,
                DROP COLUMN IF EXISTS maker_id,
                DROP COLUMN IF EXISTS checker_id
        ");
    }
};
