<?php

declare(strict_types=1);

// STUB — service layer blocked pending AD-14 resolution

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'payment';

    public function up(): void
    {
        Schema::connection('payment')->create('payout_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payout_request_id');
            $table->char('operation_id', 64);
            $table->string('provider_code', 50);
            $table->string('provider_reference', 200)->nullable();
            $table->string('status', 30);
            $table->char('request_payload_hash', 64);
            $table->string('response_code', 50)->nullable();
            $table->text('response_message')->nullable();
            $table->integer('query_attempts')->default(0);
            $table->timestampTz('submitted_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payout_attempts
             ADD CONSTRAINT payout_attempts_status_check
             CHECK (status IN ('submitted','pending','succeeded','failed','unknown'))"
        );

        DB::connection('payment')->statement(
            'CREATE INDEX payout_attempts_payout_request_id_idx
             ON payment.payout_attempts (payout_request_id)'
        );

        DB::connection('payment')->statement(
            'CREATE UNIQUE INDEX payout_attempts_operation_id_idx
             ON payment.payout_attempts (operation_id)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX payout_attempts_status_idx
             ON payment.payout_attempts (status)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX payout_attempts_submitted_at_idx
             ON payment.payout_attempts (submitted_at)'
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payout_attempts
             ADD CONSTRAINT payout_attempts_request_fk
             FOREIGN KEY (payout_request_id) REFERENCES payment.payout_requests (id)"
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payout_attempts_payout_request_id_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payout_attempts_operation_id_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payout_attempts_status_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payout_attempts_submitted_at_idx'
        );
        Schema::connection('payment')->dropIfExists('payout_attempts');
    }
};
