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
        Schema::connection('payment')->create('payment.payment_callbacks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider_code', 64)->notNullable();
            $table->text('raw_body')->notNullable();
            $table->jsonb('headers')->default('{}')->notNullable();
            $table->string('signature_header', 500)->nullable();
            $table->string('idempotency_key', 500)->nullable();
            $table->string('status', 32)->default('received')->notNullable();
            $table->uuid('payment_attempt_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('received_at')->notNullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('created_at')->notNullable()->useCurrent();
        });

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_callbacks ADD CONSTRAINT payment_callbacks_status_check
             CHECK (status IN ('received','processing','processed','signature_invalid','duplicate','dead_lettered','failed'))"
        );

        DB::connection('payment')->statement(
            'CREATE UNIQUE INDEX payment_callbacks_idempotency_idx
             ON payment.payment_callbacks (provider_code, idempotency_key)
             WHERE idempotency_key IS NOT NULL'
        );
        DB::connection('payment')->statement(
            'CREATE INDEX payment_callbacks_status_idx
             ON payment.payment_callbacks (status)'
        );
        DB::connection('payment')->statement(
            'CREATE INDEX payment_callbacks_received_at_idx
             ON payment.payment_callbacks (received_at)'
        );
    }

    public function down(): void
    {
        Schema::connection('payment')->dropIfExists('payment.payment_callbacks');
    }
};
