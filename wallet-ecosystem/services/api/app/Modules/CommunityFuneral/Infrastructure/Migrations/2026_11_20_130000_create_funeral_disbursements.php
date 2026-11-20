<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        Schema::connection('community_services')->create('funeral_disbursements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('collection_id')->unique();
            $table->uuid('beneficiary_id');
            $table->string('destination_type', 24);
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('status', 24)->default('awaiting_approval');
            $table->uuid('requested_by');
            $table->timestampTz('requested_at');
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->string('provider_reference', 200)->nullable();
            $table->string('receipt_reference', 200)->nullable();
            $table->uuid('ledger_journal_id')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'requested_at'], 'funeral_disbursements_status_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('community_services')->dropIfExists('funeral_disbursements');
    }
};
