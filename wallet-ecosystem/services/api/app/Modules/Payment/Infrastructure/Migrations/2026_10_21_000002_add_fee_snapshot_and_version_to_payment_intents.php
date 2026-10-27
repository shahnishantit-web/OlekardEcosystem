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
            $table->bigInteger('version')->notNullable()->default(1)->after('fulfilment_status');
            $table->bigInteger('fee_quote_minor')->notNullable()->default(0)->after('version');
            $table->string('fee_quote_provider_code', 64)->nullable()->after('fee_quote_minor');
        });

        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_intents
             ADD CONSTRAINT payment_intents_fee_quote_minor_check
             CHECK (fee_quote_minor >= 0)'
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_intents
             DROP CONSTRAINT IF EXISTS payment_intents_fee_quote_minor_check,
             DROP COLUMN IF EXISTS version,
             DROP COLUMN IF EXISTS fee_quote_minor,
             DROP COLUMN IF EXISTS fee_quote_provider_code'
        );
    }
};
