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
        Schema::connection('ledger')->create('ledger.journals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('idempotency_key', 255)->unique()->notNullable();
            $table->string('entry_type', 20)->notNullable();
            $table->string('currency_code', 10)->notNullable();
            $table->string('status', 10)->default('posted')->notNullable();
            $table->string('transaction_reference', 255)->nullable();
            $table->uuid('reversed_journal_id')->nullable();
            $table->string('correlation_id', 255)->notNullable();
            $table->text('description')->nullable();
            $table->timestampTz('posted_at')->notNullable();
            $table->uuid('created_by')->notNullable();
            // No updated_at — deliberate; signals immutability
            $table->timestampTz('created_at')->notNullable()->useCurrent();
        });

        DB::connection('ledger')->statement(
            "ALTER TABLE ledger.journals ADD CONSTRAINT journals_entry_type_check
             CHECK (entry_type IN ('debit','credit','fee','revenue','commission','reversal'))"
        );
        DB::connection('ledger')->statement(
            "ALTER TABLE ledger.journals ADD CONSTRAINT journals_status_check
             CHECK (status IN ('posted'))"
        );

        DB::connection('ledger')->statement(
            'ALTER TABLE ledger.journals ADD CONSTRAINT journals_reversed_journal_id_fk
             FOREIGN KEY (reversed_journal_id) REFERENCES ledger.journals(id)'
        );

        DB::connection('ledger')->statement(
            'CREATE INDEX journals_transaction_reference_idx ON ledger.journals (transaction_reference)'
        );
        DB::connection('ledger')->statement(
            'CREATE INDEX journals_reversed_journal_id_idx ON ledger.journals (reversed_journal_id)'
        );
        DB::connection('ledger')->statement(
            'CREATE INDEX journals_entry_type_status_idx ON ledger.journals (entry_type, status)'
        );
        DB::connection('ledger')->statement(
            'CREATE INDEX journals_posted_at_desc_idx ON ledger.journals (posted_at DESC)'
        );
    }

    public function down(): void
    {
        Schema::connection('ledger')->dropIfExists('ledger.journals');
    }
};
