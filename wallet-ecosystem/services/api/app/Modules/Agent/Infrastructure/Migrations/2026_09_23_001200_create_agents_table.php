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
        Schema::connection('platform')->create('agent.agents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->uuid('user_id');
            $table->string('agent_type', 20);
            $table->string('status', 20)->default('active');
            $table->uuid('approved_by');
            $table->timestampTz('approved_at');
            $table->uuid('suspended_by')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->uuid('reactivated_by')->nullable();
            $table->timestampTz('reactivated_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
        });

        DB::connection('platform')->statement(
            "ALTER TABLE agent.agents ADD CONSTRAINT agents_status_check CHECK (status IN ('active','suspended'))"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE agent.agents ADD CONSTRAINT agents_agent_type_check CHECK (agent_type IN ('individual','business'))"
        );

        DB::connection('platform')->statement(
            'ALTER TABLE agent.agents ADD CONSTRAINT agents_application_id_fk FOREIGN KEY (application_id) REFERENCES agent.agent_applications(id)'
        );

        DB::connection('platform')->statement(
            'ALTER TABLE agent.agents ADD CONSTRAINT agents_user_id_fk FOREIGN KEY (user_id) REFERENCES identity.users(id) DEFERRABLE INITIALLY DEFERRED'
        );

        // CC-E12-001: UNIQUE on application_id prevents duplicate agent creation under concurrent approvals
        DB::connection('platform')->statement(
            'CREATE UNIQUE INDEX agents_application_id_unique_idx ON agent.agents (application_id)'
        );

        DB::connection('platform')->statement(
            'CREATE UNIQUE INDEX agents_user_id_unique_idx ON agent.agents (user_id)'
        );

        DB::connection('platform')->statement(
            'CREATE INDEX agents_status_idx ON agent.agents (status)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('agent.agents');
    }
};
