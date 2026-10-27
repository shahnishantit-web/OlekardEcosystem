<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection('platform')->statement('CREATE SCHEMA IF NOT EXISTS community_services');

        Schema::connection('community_services')->create('funeral_communities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organisation_id');
            $table->string('display_name', 200);
            $table->string('status', 24)->default('active');
            $table->string('policy_reference', 200)->nullable();
            $table->string('policy_version', 64)->default('lc06-v1.1');
            $table->uuid('created_by');
            $table->timestampsTz();
            $table->unique(['organisation_id', 'display_name'], 'funeral_communities_org_name_uq');
        });

        Schema::connection('community_services')->create('funeral_community_staff', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('community_id')->index();
            $table->uuid('actor_id')->index();
            $table->string('role', 24)->default('reviewer');
            $table->string('status', 16)->default('active');
            $table->uuid('granted_by');
            $table->timestampsTz();
            $table->unique(['community_id', 'actor_id'], 'funeral_community_staff_member_uq');
        });

        Schema::connection('community_services')->create('funeral_households', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('community_id')->index();
            $table->string('private_reference', 200);
            $table->string('status', 24)->default('pending_review');
            $table->string('exemption_reference', 200)->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->uuid('created_by');
            $table->timestampsTz();
            $table->unique(['community_id', 'private_reference'], 'funeral_households_community_ref_uq');
        });

        Schema::connection('community_services')->create('funeral_representatives', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('household_id')->index();
            $table->uuid('actor_id')->index();
            $table->string('status', 24)->default('unverified');
            $table->string('evidence_reference', 500)->nullable();
            $table->uuid('verified_by')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
            $table->unique(['household_id', 'actor_id'], 'funeral_representatives_household_actor_uq');
        });

        Schema::connection('community_services')->create('funeral_collections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('community_id')->index();
            $table->string('status', 24)->default('under_review');
            $table->uuid('beneficiary_id');
            $table->string('evidence_reference', 500)->nullable();
            $table->bigInteger('amount_minor')->default(2000);
            $table->char('currency_code', 3)->default('BWP');
            $table->string('terms_version', 64)->default('lc06-v1.1');
            $table->uuid('created_by');
            $table->uuid('reviewed_by')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->uuid('snapshot_id')->nullable();
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->index(['community_id', 'status'], 'funeral_collections_community_status_idx');
        });

        Schema::connection('community_services')->create('funeral_eligibility_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('collection_id')->unique();
            $table->unsignedInteger('version');
            $table->string('terms_version', 64);
            $table->unsignedInteger('eligible_count');
            $table->unsignedInteger('exempt_count');
            $table->char('membership_digest', 64);
            $table->uuid('activated_by');
            $table->timestampTz('frozen_at');
            $table->timestampsTz();
        });

        Schema::connection('community_services')->create('funeral_snapshot_households', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('snapshot_id')->index();
            $table->uuid('household_id')->index();
            $table->string('eligibility_status', 16);
            $table->string('decision_reference', 200)->nullable();
            $table->timestampsTz();
            $table->unique(['snapshot_id', 'household_id'], 'funeral_snapshot_household_uq');
        });

        Schema::connection('community_services')->create('funeral_obligations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('collection_id')->index();
            $table->uuid('snapshot_household_id')->unique();
            $table->uuid('household_id');
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('status', 24)->default('unpaid');
            $table->string('fulfilment_status', 24)->default('pending');
            $table->uuid('payment_intent_id')->nullable()->unique();
            $table->timestampTz('fulfilled_at')->nullable();
            $table->timestampsTz();
            $table->unique(['collection_id', 'household_id'], 'funeral_obligation_collection_household_uq');
        });

        Schema::connection('community_services')->create('funeral_payment_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('obligation_id')->index();
            $table->uuid('payment_intent_id')->unique();
            $table->bigInteger('gross_amount_minor');
            $table->bigInteger('fee_amount_minor');
            $table->char('currency_code', 3);
            $table->string('domain_status', 24);
            $table->string('correlation_id', 100)->nullable();
            $table->timestampTz('confirmed_at');
            $table->timestampsTz();
        });

        Schema::connection('community_services')->create('funeral_late_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('collection_id')->index();
            $table->uuid('obligation_id')->index();
            $table->uuid('payment_intent_id')->unique();
            $table->string('reason', 64);
            $table->string('status', 24)->default('unresolved');
            $table->string('evidence_reference', 500)->nullable();
            $table->timestampsTz();
        });

        Schema::connection('community_services')->create('funeral_closures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('collection_id')->unique();
            $table->uuid('closed_by');
            $table->timestampTz('closed_at');
            $table->bigInteger('confirmed_gross_minor')->default(0);
            $table->bigInteger('fee_total_minor')->default(0);
            $table->bigInteger('confirmed_refunds_minor')->default(0);
            $table->bigInteger('net_support_minor')->default(0);
            $table->bigInteger('late_review_gross_minor')->default(0);
            $table->unsignedInteger('unresolved_liability_count')->default(0);
            $table->string('settlement_status', 24)->default('unresolved');
            $table->string('payout_status', 24)->default('blocked');
            $table->string('carry_forward_reference', 200)->nullable();
            $table->uuid('carry_forward_approved_by')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        foreach ([
            'funeral_closures', 'funeral_late_reviews', 'funeral_payment_receipts', 'funeral_obligations',
            'funeral_snapshot_households', 'funeral_eligibility_snapshots', 'funeral_collections',
            'funeral_representatives', 'funeral_households', 'funeral_community_staff', 'funeral_communities',
        ] as $table) {
            Schema::connection('community_services')->dropIfExists($table);
        }
    }
};
