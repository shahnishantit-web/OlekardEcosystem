<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ledger';

    public function up(): void
    {
        Schema::connection('ledger')->create('funding.bank_reconciliations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('funding_transaction_id')->notNullable();
            $table->string('bank_notification_id', 255)->unique()->notNullable();
            $table->string('bank_reference_code', 64)->notNullable();
            $table->bigInteger('matched_amount')->notNullable();
            $table->char('currency_code', 3)->notNullable();
            $table->timestampTz('reconciled_at')->notNullable();
            $table->timestampsTz();
        });

        DB::connection('ledger')->statement(
            'CREATE INDEX bank_reconciliations_transaction_idx ON funding.bank_reconciliations (funding_transaction_id)'
        );
        DB::connection('ledger')->statement(
            'CREATE INDEX bank_reconciliations_reconciled_at_idx ON funding.bank_reconciliations (reconciled_at)'
        );
    }

    public function down(): void
    {
        Schema::connection('ledger')->dropIfExists('funding.bank_reconciliations');
    }
};
