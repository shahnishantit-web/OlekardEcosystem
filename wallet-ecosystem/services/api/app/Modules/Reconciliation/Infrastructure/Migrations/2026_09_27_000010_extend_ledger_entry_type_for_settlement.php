<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E20: Extends the ledger journals entry_type CHECK constraint to allow 'settlement'.
 * The JournalEntryType enum is extended correspondingly. No historical entries are touched.
 */
return new class extends Migration
{
    protected $connection = 'ledger';

    public function up(): void
    {
        DB::connection('ledger')->statement(
            "ALTER TABLE ledger.journals DROP CONSTRAINT IF EXISTS journals_entry_type_check"
        );
        DB::connection('ledger')->statement(
            "ALTER TABLE ledger.journals ADD CONSTRAINT journals_entry_type_check
             CHECK (entry_type IN ('debit','credit','fee','revenue','commission','reversal','settlement'))"
        );
    }

    public function down(): void
    {
        DB::connection('ledger')->statement(
            "ALTER TABLE ledger.journals DROP CONSTRAINT IF EXISTS journals_entry_type_check"
        );
        DB::connection('ledger')->statement(
            "ALTER TABLE ledger.journals ADD CONSTRAINT journals_entry_type_check
             CHECK (entry_type IN ('debit','credit','fee','revenue','commission','reversal'))"
        );
    }
};
