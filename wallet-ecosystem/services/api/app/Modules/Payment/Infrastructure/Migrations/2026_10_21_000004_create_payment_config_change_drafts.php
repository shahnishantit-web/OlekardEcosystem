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
        Schema::connection('payment')->create('payment.payment_config_change_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('provider_id');
            $table->foreign('provider_id')
                ->references('id')
                ->on('payment.payment_providers')
                ->restrictOnDelete();
            $table->string('change_type', 64)->comment('high_risk_update|credential_rotation');
            $table->jsonb('change_payload')->comment('Proposed new values. MUST NOT contain raw credentials.');
            $table->string('status', 32)->default('pending_approval');
            $table->uuid('drafted_by_actor_id');
            $table->uuid('reviewed_by_actor_id')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_notes', 2000)->nullable();
            $table->string('idempotency_key', 255)->unique();
            $table->uuid('correlation_id');
            $table->timestampsTz();
        });

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_config_change_drafts
             ADD CONSTRAINT chk_payment_config_drafts_status
             CHECK (status IN ('pending_approval','approved','rejected','withdrawn'))"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_config_change_drafts
             ADD CONSTRAINT chk_payment_config_drafts_change_type
             CHECK (change_type IN ('high_risk_update','credential_rotation'))"
        );

        // Self-approval prevention enforced at DB level
        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_config_change_drafts
             ADD CONSTRAINT chk_payment_config_drafts_self_approval
             CHECK (reviewed_by_actor_id IS NULL OR reviewed_by_actor_id != drafted_by_actor_id)"
        );

        DB::connection('payment')->statement(
            "CREATE INDEX idx_payment_config_drafts_provider_status
             ON payment.payment_config_change_drafts (provider_id, status)"
        );

        DB::connection('payment')->statement(
            "CREATE INDEX idx_payment_config_drafts_drafter
             ON payment.payment_config_change_drafts (drafted_by_actor_id, status)"
        );
    }

    public function down(): void
    {
        Schema::connection('payment')->dropIfExists('payment.payment_config_change_drafts');
    }
};
