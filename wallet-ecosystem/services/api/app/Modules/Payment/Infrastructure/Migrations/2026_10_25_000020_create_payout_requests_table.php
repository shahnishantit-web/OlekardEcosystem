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
        Schema::connection('payment')->create('payout_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('idempotency_key', 200);
            $table->uuid('fund_reference_id');
            $table->string('purpose_type', 100);
            $table->uuid('organisation_id');
            $table->uuid('beneficiary_id');
            $table->char('currency_code', 3);
            $table->bigInteger('requested_amount_minor');
            $table->bigInteger('computed_payable_minor');
            $table->uuid('custody_wallet_id');
            $table->string('status', 30)->default('pending_verification');
            $table->char('operation_id', 64)->nullable();
            $table->string('provider_code', 50)->nullable();
            $table->string('ledger_entry_id', 200)->nullable();
            $table->uuid('created_by');
            $table->timestampTz('settled_at')->nullable();
            $table->timestampsTz();
        });

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payout_requests
             ADD CONSTRAINT payout_requests_status_check
             CHECK (status IN ('pending_verification','awaiting_approval','reserved','approved','executing','pending_settlement','settled','failed','manual_review','cancelled'))"
        );

        DB::connection('payment')->statement(
            'CREATE UNIQUE INDEX payout_requests_idempotency_key_idx
             ON payment.payout_requests (idempotency_key)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX payout_requests_fund_reference_id_idx
             ON payment.payout_requests (fund_reference_id)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX payout_requests_organisation_id_idx
             ON payment.payout_requests (organisation_id)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX payout_requests_beneficiary_id_idx
             ON payment.payout_requests (beneficiary_id)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX payout_requests_status_idx
             ON payment.payout_requests (status)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX payout_requests_created_at_idx
             ON payment.payout_requests (created_at)'
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payout_requests
             ADD CONSTRAINT payout_requests_beneficiary_fk
             FOREIGN KEY (beneficiary_id) REFERENCES payment.payout_beneficiaries (id)"
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payout_requests_idempotency_key_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payout_requests_fund_reference_id_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payout_requests_organisation_id_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payout_requests_beneficiary_id_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payout_requests_status_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.payout_requests_created_at_idx'
        );
        Schema::connection('payment')->dropIfExists('payout_requests');
    }
};
