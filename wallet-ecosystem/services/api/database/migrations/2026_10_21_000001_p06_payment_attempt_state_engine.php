<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'payment';

    public function up(): void
    {
        // Add operation_id, quote_id, quote_version to payment_attempts
        DB::connection('payment')->statement('
            ALTER TABLE payment.payment_attempts
                ADD COLUMN IF NOT EXISTS operation_id UUID NOT NULL DEFAULT gen_random_uuid(),
                ADD COLUMN IF NOT EXISTS quote_id     UUID NULL,
                ADD COLUMN IF NOT EXISTS quote_version INT  NULL
        ');

        DB::connection('payment')->statement('
            CREATE UNIQUE INDEX IF NOT EXISTS payment_attempts_operation_id_unique
                ON payment.payment_attempts (operation_id)
        ');
    }

    public function down(): void
    {
        DB::connection('payment')->statement('
            DROP INDEX IF EXISTS payment.payment_attempts_operation_id_unique
        ');

        DB::connection('payment')->statement('
            ALTER TABLE payment.payment_attempts
                DROP COLUMN IF EXISTS operation_id,
                DROP COLUMN IF EXISTS quote_id,
                DROP COLUMN IF EXISTS quote_version
        ');
    }
};
