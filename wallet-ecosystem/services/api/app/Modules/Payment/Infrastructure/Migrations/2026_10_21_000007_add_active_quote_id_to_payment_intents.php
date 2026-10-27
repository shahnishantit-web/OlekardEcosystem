<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'payment';

    public function up(): void
    {
        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_intents
             ADD COLUMN IF NOT EXISTS active_quote_id UUID NULL'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX IF NOT EXISTS payment_intents_active_quote_idx
             ON payment.payment_intents (active_quote_id)
             WHERE active_quote_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        if (Schema::connection('payment')->hasColumn('payment.payment_intents', 'active_quote_id')) {
            DB::connection('payment')->statement(
                'ALTER TABLE payment.payment_intents DROP COLUMN IF EXISTS active_quote_id'
            );
        }
    }
};
