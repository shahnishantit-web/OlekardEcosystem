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
        Schema::connection('ledger')->create('funding.unmatched_deposits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('bank_notification_id', 255)->unique()->notNullable();
            $table->bigInteger('amount')->notNullable();
            $table->char('currency_code', 3)->notNullable();
            $table->string('raw_reference', 255)->nullable();
            $table->text('narration')->nullable();
            $table->timestampTz('received_at')->notNullable();
            $table->string('resolution_status', 32)->default('pending')->notNullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->jsonb('raw_payload')->notNullable();
            $table->timestampsTz();
        });

        DB::connection('ledger')->statement(
            "ALTER TABLE funding.unmatched_deposits ADD CONSTRAINT unmatched_deposits_resolution_status_check
             CHECK (resolution_status IN ('pending','credited','returned'))"
        );

        DB::connection('ledger')->statement(
            'CREATE INDEX unmatched_deposits_resolution_idx ON funding.unmatched_deposits (resolution_status, received_at)'
        );
    }

    public function down(): void
    {
        Schema::connection('ledger')->dropIfExists('funding.unmatched_deposits');
    }
};
