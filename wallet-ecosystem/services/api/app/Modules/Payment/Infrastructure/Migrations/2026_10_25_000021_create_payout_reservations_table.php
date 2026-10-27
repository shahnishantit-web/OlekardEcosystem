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
        Schema::connection('payment')->create('payout_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payout_request_id');
            $table->bigInteger('reserved_amount_minor');
            $table->char('currency_code', 3);
            $table->uuid('custody_wallet_id');
            $table->string('status', 30)->default('awaiting_approval');
            $table->uuid('maker_id');
            $table->uuid('approver_id')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampTz('expires_at');
            $table->char('amount_hash', 64);
            $table->timestampsTz();
        });

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payout_reservations
             ADD CONSTRAINT payout_reservations_status_check
             CHECK (status IN ('awaiting_approval','approved','invalidated','rejected','expired','released'))"
        );

        DB::connection('payment')->statement(
            'CREATE UNIQUE INDEX payout_reservations_payout_request_id_idx
             ON payment.payout_reservations (payout_request_id)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX payout_reservations_maker_id_idx
             ON payment.payout_reservations (maker_id)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX payout_reservations_approver_id_idx
             ON payment.payout_reservations (approver_id)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX payout_reservations_status_idx
             ON payment.payout_reservations (status)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX payout_reservations_expires_at_idx
             ON payment.payout_reservations (expires_at)'
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payout_reservations
             ADD CONSTRAINT payout_reservations_request_fk
             FOREIGN KEY (payout_request_id) REFERENCES payment.payout_requests (id)"
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payout_reservations_payout_request_id_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payout_reservations_maker_id_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payout_reservations_approver_id_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payout_reservations_status_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payout_reservations_expires_at_idx'
        );
        Schema::connection('payment')->dropIfExists('payout_reservations');
    }
};
