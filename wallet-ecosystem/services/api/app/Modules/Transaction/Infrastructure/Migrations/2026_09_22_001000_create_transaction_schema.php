<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS transaction');
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS transaction CASCADE');
    }
};
