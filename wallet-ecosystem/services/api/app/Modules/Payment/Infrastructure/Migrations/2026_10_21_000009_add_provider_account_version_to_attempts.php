<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'payment';

    public function up(): void
    {
        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_attempts
             ADD COLUMN IF NOT EXISTS provider_account_version INTEGER NULL'
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_attempts
             DROP COLUMN IF EXISTS provider_account_version'
        );
    }
};
