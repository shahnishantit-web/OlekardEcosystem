<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'payment';

    public function up(): void
    {
        Schema::connection('payment')->create('receipt_download_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payment_intent_id');
            $table->string('requestor_id', 100);
            $table->char('token', 64)->unique();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
        });

        \Illuminate\Support\Facades\DB::connection('payment')->statement(
            'CREATE INDEX rdt_payment_intent_id_idx
             ON payment.receipt_download_tokens (payment_intent_id)'
        );

        \Illuminate\Support\Facades\DB::connection('payment')->statement(
            'CREATE INDEX rdt_requestor_created_idx
             ON payment.receipt_download_tokens (requestor_id, created_at)'
        );

        \Illuminate\Support\Facades\DB::connection('payment')->statement(
            'ALTER TABLE payment.receipt_download_tokens
             ADD CONSTRAINT rdt_payment_intent_fk
             FOREIGN KEY (payment_intent_id) REFERENCES payment.payment_intents (id)'
        );
    }

    public function down(): void
    {
        Schema::connection('payment')->dropIfExists('receipt_download_tokens');
    }
};
