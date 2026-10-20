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
        Schema::connection('ledger')->create('funding.funding_provider_callbacks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('funding_transaction_id')->nullable();
            $table->string('provider', 64)->notNullable();
            $table->string('provider_reference_id', 255)->notNullable();
            $table->string('status', 32)->notNullable();
            $table->jsonb('raw_payload')->notNullable();
            $table->boolean('signature_valid')->notNullable();
            $table->timestampTz('received_at')->notNullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
        });

        DB::connection('ledger')->statement(
            "ALTER TABLE funding.funding_provider_callbacks ADD CONSTRAINT funding_callbacks_status_check
             CHECK (status IN ('success','failure','unknown'))"
        );

        DB::connection('ledger')->statement(
            'CREATE INDEX funding_callbacks_provider_ref_idx ON funding.funding_provider_callbacks (provider_reference_id)'
        );
        DB::connection('ledger')->statement(
            'CREATE INDEX funding_callbacks_transaction_idx ON funding.funding_provider_callbacks (funding_transaction_id)'
        );
        DB::connection('ledger')->statement(
            'CREATE INDEX funding_callbacks_received_at_idx ON funding.funding_provider_callbacks (received_at)'
        );
    }

    public function down(): void
    {
        Schema::connection('ledger')->dropIfExists('funding.funding_provider_callbacks');
    }
};
