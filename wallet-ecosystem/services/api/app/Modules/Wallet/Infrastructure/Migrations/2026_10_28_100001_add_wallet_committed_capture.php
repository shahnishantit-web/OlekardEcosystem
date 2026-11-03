<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'wallet';

    public function up(): void
    {
        $schema = Schema::connection('wallet');
        if (! $schema->hasColumn('wallet.wallet_holds', 'captured_at')) {
            $schema->table('wallet.wallet_holds', static function (Blueprint $table): void {
                $table->timestampTz('captured_at')->nullable();
            });
        }

        DB::connection('wallet')->statement(
            'ALTER TABLE wallet.wallet_holds DROP CONSTRAINT IF EXISTS wallet_holds_status_check'
        );
        DB::connection('wallet')->statement(
            "ALTER TABLE wallet.wallet_holds ADD CONSTRAINT wallet_holds_status_check CHECK (status IN ('active','released','expired','captured'))"
        );

        if (! $schema->hasTable('wallet.wallet_capture_records')) {
            $schema->create('wallet.wallet_capture_records', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('hold_id')->unique();
                $table->uuid('owner_actor_id');
                $table->uuid('payment_attempt_id');
                $table->uuid('payment_intent_id');
                $table->string('currency_code', 10);
                $table->bigInteger('amount_minor')->unsigned();
                $table->string('ledger_journal_id', 36)->unique();
                $table->string('ledger_idempotency_key', 255)->unique();
                $table->string('debit_account_reference', 255);
                $table->string('credit_account_reference', 255);
                $table->uuid('correlation_id');
                $table->timestampTz('captured_at');
            });
        }

        DB::connection('wallet')->statement(
            'ALTER TABLE wallet.wallet_capture_records ADD CONSTRAINT wallet_capture_records_amount_positive CHECK (amount_minor > 0)'
        );
        DB::connection('wallet')->statement(
            'ALTER TABLE wallet.wallet_capture_records ADD CONSTRAINT wallet_capture_records_hold_fk FOREIGN KEY (hold_id) REFERENCES wallet.wallet_holds(id)'
        );
    }

    public function down(): void
    {
        Schema::connection('wallet')->dropIfExists('wallet.wallet_capture_records');
        Schema::connection('wallet')->table('wallet.wallet_holds', static function (Blueprint $table): void {
            $table->dropColumn('captured_at');
        });
        DB::connection('wallet')->statement(
            "ALTER TABLE wallet.wallet_holds DROP CONSTRAINT IF EXISTS wallet_holds_status_check"
        );
        DB::connection('wallet')->statement(
            "ALTER TABLE wallet.wallet_holds ADD CONSTRAINT wallet_holds_status_check CHECK (status IN ('active','released','expired'))"
        );
    }
};
