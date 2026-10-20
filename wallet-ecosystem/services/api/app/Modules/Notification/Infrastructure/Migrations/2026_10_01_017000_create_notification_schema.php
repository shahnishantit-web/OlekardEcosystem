<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'notification';

    public function up(): void
    {
        DB::connection('notification')->statement('CREATE SCHEMA IF NOT EXISTS notification');
    }

    public function down(): void
    {
        DB::connection('notification')->statement('DROP SCHEMA IF EXISTS notification CASCADE');
    }
};
