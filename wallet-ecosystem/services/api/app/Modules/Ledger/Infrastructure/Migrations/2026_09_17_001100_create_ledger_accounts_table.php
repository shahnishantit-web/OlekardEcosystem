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
        Schema::connection('ledger')->create('ledger.ledger_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('ledger_account_reference', 255)->unique()->notNullable();
            $table->string('account_type', 20)->notNullable();
            $table->string('normal_balance', 10)->notNullable();
            $table->string('currency_code', 10)->notNullable();
            $table->uuid('owner_reference')->nullable();
            $table->string('owner_type', 50)->nullable();
            $table->string('status', 10)->default('active')->notNullable();
            $table->uuid('created_by')->notNullable();
            $table->timestampsTz();
        });

        DB::connection('ledger')->statement(
            "ALTER TABLE ledger.ledger_accounts ADD CONSTRAINT ledger_accounts_account_type_check
             CHECK (account_type IN ('asset','liability','income','expense','equity'))"
        );
        DB::connection('ledger')->statement(
            "ALTER TABLE ledger.ledger_accounts ADD CONSTRAINT ledger_accounts_normal_balance_check
             CHECK (normal_balance IN ('debit','credit'))"
        );
        DB::connection('ledger')->statement(
            "ALTER TABLE ledger.ledger_accounts ADD CONSTRAINT ledger_accounts_status_check
             CHECK (status IN ('active','closed'))"
        );

        // Partial index: skips NULL owner rows (auto-registered accounts)
        DB::connection('ledger')->statement(
            'CREATE INDEX ledger_accounts_owner_ref_type_idx ON ledger.ledger_accounts (owner_reference, owner_type)
             WHERE owner_reference IS NOT NULL'
        );
        DB::connection('ledger')->statement(
            'CREATE INDEX ledger_accounts_currency_status_idx ON ledger.ledger_accounts (currency_code, status)'
        );
    }

    public function down(): void
    {
        Schema::connection('ledger')->dropIfExists('ledger.ledger_accounts');
    }
};
