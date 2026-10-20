<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS platform');
        DB::statement('CREATE SCHEMA IF NOT EXISTS identity');
        DB::statement('CREATE SCHEMA IF NOT EXISTS wallet');
        DB::statement('CREATE SCHEMA IF NOT EXISTS ledger');
        DB::statement('CREATE SCHEMA IF NOT EXISTS kyc');
        DB::statement('CREATE SCHEMA IF NOT EXISTS transaction');
        DB::statement('CREATE SCHEMA IF NOT EXISTS risk');
        DB::statement('CREATE SCHEMA IF NOT EXISTS agent');
        DB::statement('CREATE SCHEMA IF NOT EXISTS notification');
    }

    public function down(): void
    {
        // Only drop in development — never in production.
        // Schemas are intentionally not dropped: domain epics may have run after this migration.
    }
};
