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
        Schema::connection('community_services')->create('funeral_offline_contributions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('collection_id')->index();
            $table->uuid('obligation_id')->index();
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('reference', 120);
            $table->string('evidence_reference', 500)->nullable();
            $table->timestampTz('received_at');
            $table->string('status', 24)->default('pending_review');
            $table->uuid('recorded_by');
            $table->uuid('reviewed_by')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestampsTz();
            $table->unique(['collection_id', 'reference'], 'funeral_offline_collection_ref_uq');
            $table->index(['collection_id', 'status'], 'funeral_offline_collection_status_idx');
        });

        Schema::connection('community_services')->table('funeral_disbursements', function (Blueprint $table): void {
            $table->uuid('beneficiary_id')->nullable()->change();
            $table->uuid('recipient_actor_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection('community_services')->table('funeral_disbursements', function (Blueprint $table): void {
            $table->dropColumn('recipient_actor_id');
            $table->uuid('beneficiary_id')->nullable(false)->change();
        });
        Schema::connection('community_services')->dropIfExists('funeral_offline_contributions');
    }
};
