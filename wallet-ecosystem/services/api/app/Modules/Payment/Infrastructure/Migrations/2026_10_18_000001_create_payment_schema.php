<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'payment';

    public function up(): void
    {
        DB::connection('payment')->statement('CREATE SCHEMA IF NOT EXISTS payment');
    }

    public function down(): void
    {
        DB::connection('payment')->statement('DROP SCHEMA IF EXISTS payment CASCADE');
    }
};
