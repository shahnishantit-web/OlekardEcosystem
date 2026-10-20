<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection('platform')->statement(
            "ALTER TABLE platform.configurations ADD COLUMN IF NOT EXISTS domain VARCHAR(50) NOT NULL DEFAULT 'operational_policy'"
        );

        // Backfill from key prefix pattern; drop default once all rows are set
        DB::connection('platform')->statement("
            UPDATE platform.configurations
            SET domain = CASE
                WHEN config_key LIKE 'financial_policy.%' THEN 'financial_policy'
                WHEN config_key LIKE 'geographic.%'       THEN 'geographic'
                ELSE 'operational_policy'
            END
        ");

        DB::connection('platform')->statement(
            'ALTER TABLE platform.configurations ALTER COLUMN domain DROP DEFAULT'
        );
    }

    public function down(): void
    {
        DB::connection('platform')->statement(
            'ALTER TABLE platform.configurations DROP COLUMN IF EXISTS domain'
        );
    }
};
