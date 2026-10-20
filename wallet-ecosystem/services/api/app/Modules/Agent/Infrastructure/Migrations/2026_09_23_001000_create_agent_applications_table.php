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
        Schema::connection('platform')->create('agent.agent_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('agent_type', 20);
            $table->string('status', 30)->default('draft');
            $table->jsonb('personal_info')->nullable();
            $table->jsonb('business_info')->nullable();
            $table->string('kyc_provider_reference', 255)->nullable();
            $table->string('kyc_result', 30)->nullable();
            $table->text('kyc_failure_reason')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->uuid('last_updated_by')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('kyc_initiated_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->uuid('correlation_id');
            $table->string('idempotency_key', 255)->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
        });

        DB::connection('platform')->statement(
            "ALTER TABLE agent.agent_applications ADD CONSTRAINT aa_agent_type_check CHECK (agent_type IN ('individual','business'))"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE agent.agent_applications ADD CONSTRAINT aa_status_check CHECK (status IN ('draft','in_progress','submitted','kyc_in_progress','kyc_passed','kyc_failed','under_review','approved','rejected'))"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE agent.agent_applications ADD CONSTRAINT aa_kyc_result_check CHECK (kyc_result IS NULL OR kyc_result IN ('passed','failed','pending'))"
        );

        DB::connection('platform')->statement(
            'ALTER TABLE agent.agent_applications ADD CONSTRAINT aa_user_id_fk FOREIGN KEY (user_id) REFERENCES identity.users(id) DEFERRABLE INITIALLY DEFERRED'
        );

        // Partial UNIQUE: one active application per user (rejected applications don't count)
        DB::connection('platform')->statement(
            "CREATE UNIQUE INDEX aa_user_id_active_idx ON agent.agent_applications (user_id) WHERE status != 'rejected'"
        );

        DB::connection('platform')->statement(
            'CREATE INDEX aa_status_idx ON agent.agent_applications (status)'
        );

        DB::connection('platform')->statement(
            'CREATE INDEX aa_user_id_status_idx ON agent.agent_applications (user_id, status)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('agent.agent_applications');
    }
};
