<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** E49 schema prerequisite for the Community Services module migration chain. */
return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection('platform')->statement('CREATE SCHEMA IF NOT EXISTS community_services');
    }

    public function down(): void
    {
        // Bootstrap never drops schemas or domain data.
    }
};
