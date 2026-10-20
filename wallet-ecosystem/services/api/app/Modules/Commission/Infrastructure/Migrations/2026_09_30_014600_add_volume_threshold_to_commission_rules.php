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
            "ALTER TABLE commission.commission_rules
             ADD COLUMN IF NOT EXISTS volume_threshold BIGINT NULL"
        );
    }

    public function down(): void
    {
        DB::connection('platform')->statement(
            "ALTER TABLE commission.commission_rules
             DROP COLUMN IF EXISTS volume_threshold"
        );
    }
};
