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
        Schema::connection('payment')->table('payment.payment_intents', function (Blueprint $table): void {
            $table->string('accounting_status', 32)->notNullable()->default('pending_ledger')->after('status');
            $table->string('fulfilment_status', 32)->notNullable()->default('pending')->after('accounting_status');
        });

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_intents
             ADD CONSTRAINT payment_intents_accounting_status_check
             CHECK (accounting_status IN ('pending_ledger','ledger_posted','ledger_failed'))"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_intents
             ADD CONSTRAINT payment_intents_fulfilment_status_check
             CHECK (fulfilment_status IN ('pending','fulfilled','fulfilment_failed'))"
        );

        DB::connection('payment')->statement(
            "CREATE INDEX payment_intents_accounting_status_idx
             ON payment.payment_intents (accounting_status)
             WHERE accounting_status = 'pending_ledger'"
        );

        DB::connection('payment')->statement(
            "CREATE INDEX payment_intents_fulfilment_status_idx
             ON payment.payment_intents (fulfilment_status)
             WHERE fulfilment_status = 'pending'"
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payment_intents_accounting_status_idx'
        );

        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payment_intents_fulfilment_status_idx'
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_intents
             DROP CONSTRAINT IF EXISTS payment_intents_accounting_status_check,
             DROP CONSTRAINT IF EXISTS payment_intents_fulfilment_status_check,
             DROP COLUMN IF EXISTS accounting_status,
             DROP COLUMN IF EXISTS fulfilment_status"
        );
    }
};
