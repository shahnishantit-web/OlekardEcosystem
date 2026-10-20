<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        Schema::connection('platform')->create('fees.fees', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('transaction_id', 36)->unique();
            $table->uuid('fee_rule_id')->nullable();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3)->default('BWP');
            $table->string('status', 10)->default('posted');
            $table->string('ledger_entry_id', 36)->nullable();
            $table->string('reversal_ledger_entry_id', 36)->nullable();
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            "ALTER TABLE fees.fees ADD CONSTRAINT fees_status_check
             CHECK (status IN ('posted','reversed'))"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE fees.fees ADD CONSTRAINT fees_amount_minor_check
             CHECK (amount_minor > 0)"
        );

        DB::connection('platform')->statement(
            "CREATE INDEX idx_fees_transaction_id ON fees.fees (transaction_id)"
        );

        DB::connection('platform')->statement(
            "CREATE INDEX idx_fees_fee_rule_id ON fees.fees (fee_rule_id)"
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('fees.fees');
    }
};
