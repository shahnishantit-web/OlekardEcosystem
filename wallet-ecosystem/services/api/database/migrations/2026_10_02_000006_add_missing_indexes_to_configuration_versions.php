<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T068 — plan T011: Add (domain, occurred_at DESC) and (event_type, occurred_at DESC)
 * indexes that enable FR-017 filtering by domain and event type.
 */
return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection('platform')->statement("
            CREATE INDEX IF NOT EXISTS idx_config_versions_domain_occurred
                ON platform.configuration_versions (domain, occurred_at DESC)
        ");

        DB::connection('platform')->statement("
            CREATE INDEX IF NOT EXISTS idx_config_versions_event_type_occurred
                ON platform.configuration_versions (event_type, occurred_at DESC)
        ");
    }

    public function down(): void
    {
        DB::connection('platform')->statement(
            'DROP INDEX IF EXISTS platform.idx_config_versions_domain_occurred'
        );

        DB::connection('platform')->statement(
            'DROP INDEX IF EXISTS platform.idx_config_versions_event_type_occurred'
        );
    }
};
