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
            $table->timestampTz('ledger_repair_at')->nullable()->after('accounting_status');
            $table->smallInteger('accounting_repair_attempts')->notNullable()->default(0)->after('ledger_repair_at');
        });
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_intents
             DROP COLUMN IF EXISTS accounting_repair_attempts,
             DROP COLUMN IF EXISTS ledger_repair_at'
        );
    }
};
