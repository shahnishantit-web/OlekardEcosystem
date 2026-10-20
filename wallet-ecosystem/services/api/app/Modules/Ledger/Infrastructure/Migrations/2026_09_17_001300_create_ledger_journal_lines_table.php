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
        Schema::connection('ledger')->create('ledger.journal_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('journal_id')->notNullable();
            $table->uuid('ledger_account_id')->notNullable();
            $table->string('direction', 10)->notNullable();
            $table->bigInteger('amount')->notNullable();
            $table->string('currency_code', 10)->notNullable();
            // No updated_at — deliberate; signals append-only
            $table->timestampTz('created_at')->notNullable()->useCurrent();
        });

        DB::connection('ledger')->statement(
            "ALTER TABLE ledger.journal_lines ADD CONSTRAINT journal_lines_direction_check
             CHECK (direction IN ('debit','credit'))"
        );
        DB::connection('ledger')->statement(
            'ALTER TABLE ledger.journal_lines ADD CONSTRAINT journal_lines_amount_positive_check
             CHECK (amount > 0)'
        );

        DB::connection('ledger')->statement(
            'ALTER TABLE ledger.journal_lines ADD CONSTRAINT journal_lines_journal_id_fk
             FOREIGN KEY (journal_id) REFERENCES ledger.journals(id)'
        );
        DB::connection('ledger')->statement(
            'ALTER TABLE ledger.journal_lines ADD CONSTRAINT journal_lines_ledger_account_id_fk
             FOREIGN KEY (ledger_account_id) REFERENCES ledger.ledger_accounts(id)'
        );

        DB::connection('ledger')->statement(
            'CREATE INDEX journal_lines_journal_id_idx ON ledger.journal_lines (journal_id)'
        );
        // Covering index for balance aggregation queries
        DB::connection('ledger')->statement(
            'CREATE INDEX journal_lines_account_direction_amount_idx ON ledger.journal_lines (ledger_account_id, direction, amount)'
        );
        DB::connection('ledger')->statement(
            'CREATE INDEX journal_lines_account_currency_idx ON ledger.journal_lines (ledger_account_id, currency_code)'
        );
    }

    public function down(): void
    {
        Schema::connection('ledger')->dropIfExists('ledger.journal_lines');
    }
};
