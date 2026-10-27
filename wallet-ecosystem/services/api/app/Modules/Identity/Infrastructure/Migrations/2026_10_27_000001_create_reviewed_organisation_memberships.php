<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        Schema::connection('identity')->create('identity.organisations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('registry_reference', 120)->unique();
            $table->string('display_name', 200);
            $table->string('status', 20)->default('pending_review');
            $table->string('evidence_reference', 255);
            $table->uuid('requested_by');
            $table->uuid('reviewed_by')->nullable();
            $table->timestampsTz();
        });
        Schema::connection('identity')->create('identity.organisation_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organisation_id');
            $table->uuid('user_id');
            $table->string('status', 20)->default('pending_review');
            $table->string('evidence_reference', 255);
            $table->uuid('requested_by');
            $table->uuid('reviewed_by')->nullable();
            $table->unique(['organisation_id', 'user_id'], 'identity_org_membership_unique');
            $table->foreign('organisation_id')->references('id')->on('identity.organisations')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('identity.users')->restrictOnDelete();
            $table->timestampsTz();
        });
        Schema::connection('identity')->create('identity.organisation_authority_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('subject_type', 20);
            $table->uuid('subject_id');
            $table->string('action', 30);
            $table->uuid('actor_id');
            $table->string('previous_status', 20)->nullable();
            $table->string('new_status', 20);
            $table->timestampTz('created_at');
            $table->index(['subject_type', 'subject_id'], 'identity_org_authority_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('identity')->dropIfExists('identity.organisation_authority_events');
        Schema::connection('identity')->dropIfExists('identity.organisation_memberships');
        Schema::connection('identity')->dropIfExists('identity.organisations');
    }
};
