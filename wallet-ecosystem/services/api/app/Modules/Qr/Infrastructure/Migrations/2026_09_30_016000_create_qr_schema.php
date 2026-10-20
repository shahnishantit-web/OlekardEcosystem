<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection('platform')->statement('CREATE SCHEMA IF NOT EXISTS qr');
    }

    public function down(): void
    {
        DB::connection('platform')->statement('DROP SCHEMA IF EXISTS qr CASCADE');
    }
};
