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
        Schema::connection('payment')->create('clearing_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payment_attempt_id');
            $table->uuid('statement_id');
            $table->uuid('statement_line_id');
            $table->string('provider_code', 50);
            $table->char('currency_code', 3);
            $table->bigInteger('cleared_amount_minor');
            $table->bigInteger('attempt_amount_minor');
            $table->string('clearing_status', 30)->default('cleared');
            $table->string('accounting_status', 40);
            $table->string('bank_settlement_batch_ref', 200)->nullable();
            $table->timestampTz('bank_settled_at')->nullable();
            $table->timestampTz('aged_at')->nullable();
            $table->timestampTz('cleared_at');
            $table->timestampsTz();
        });

        DB::connection('payment')->statement(
            'CREATE UNIQUE INDEX clearing_records_payment_attempt_id_idx
             ON payment.clearing_records (payment_attempt_id)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX clearing_records_statement_id_idx
             ON payment.clearing_records (statement_id)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX clearing_records_accounting_status_idx
             ON payment.clearing_records (accounting_status)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX clearing_records_clearing_status_idx
             ON payment.clearing_records (clearing_status)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX clearing_records_bank_settled_at_idx
             ON payment.clearing_records (bank_settled_at)'
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.clearing_records
             ADD CONSTRAINT clearing_records_attempt_fk
             FOREIGN KEY (payment_attempt_id) REFERENCES payment.payment_attempts (id)"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.clearing_records
             ADD CONSTRAINT clearing_records_statement_fk
             FOREIGN KEY (statement_id) REFERENCES payment.provider_statements (id)"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.clearing_records
             ADD CONSTRAINT clearing_records_statement_line_fk
             FOREIGN KEY (statement_line_id) REFERENCES payment.provider_statement_lines (id)"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.clearing_records
             ADD CONSTRAINT clearing_records_clearing_status_check
             CHECK (clearing_status IN ('cleared','bank_settled'))"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.clearing_records
             ADD CONSTRAINT clearing_records_accounting_status_check
             CHECK (accounting_status IN ('pending_ledger_config','qualified','excluded'))"
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.clearing_records_payment_attempt_id_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.clearing_records_statement_id_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.clearing_records_accounting_status_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.clearing_records_clearing_status_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.clearing_records_bank_settled_at_idx'
        );
        Schema::connection('payment')->dropIfExists('clearing_records');
    }
};
