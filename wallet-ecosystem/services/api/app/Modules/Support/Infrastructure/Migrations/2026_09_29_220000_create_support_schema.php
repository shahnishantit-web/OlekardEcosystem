<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'support';

    public function up(): void
    {
        DB::connection('support')->statement('CREATE SCHEMA IF NOT EXISTS support');

        DB::connection('support')->statement(
            'CREATE SEQUENCE IF NOT EXISTS support.ticket_number_seq AS BIGINT START 1 INCREMENT 1'
        );

        DB::connection('support')->statement(
            'CREATE SEQUENCE IF NOT EXISTS support.dispute_number_seq AS BIGINT START 1 INCREMENT 1'
        );
    }

    public function down(): void
    {
        DB::connection('support')->statement('DROP SEQUENCE IF EXISTS support.dispute_number_seq');
        DB::connection('support')->statement('DROP SEQUENCE IF EXISTS support.ticket_number_seq');
        DB::connection('support')->statement('DROP SCHEMA IF EXISTS support CASCADE');
    }
};
