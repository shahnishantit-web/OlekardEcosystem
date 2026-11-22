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
        Schema::connection('community_services')->table('funeral_households', function (Blueprint $table): void {
            $table->text('contact_phone_encrypted')->nullable();
            $table->timestampTz('contact_consent_at')->nullable();
        });

        Schema::connection('community_services')->table('funeral_representatives', function (Blueprint $table): void {
            $table->string('verification_method', 24)->default('staff_review');
        });

        Schema::connection('community_services')->create('funeral_household_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('collection_id')->index();
            $table->uuid('household_id')->index();
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->text('contact_phone_encrypted');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('provider_reference', 200)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('sent_at')->nullable();
            $table->uuid('created_by');
            $table->uuid('claimed_by')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['collection_id', 'household_id'], 'funeral_invitation_collection_household_uq');
            $table->index(['status', 'expires_at'], 'funeral_invitation_status_expiry_idx');
        });

    }

    public function down(): void
    {
        Schema::connection('community_services')->dropIfExists('funeral_household_invitations');
        Schema::connection('community_services')->table('funeral_representatives', function (Blueprint $table): void {
            $table->dropColumn('verification_method');
        });
        Schema::connection('community_services')->table('funeral_households', function (Blueprint $table): void {
            $table->dropColumn(['contact_phone_encrypted', 'contact_consent_at']);
        });
    }
};
