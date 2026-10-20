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
        Schema::connection('platform')->create('agent.agent_customer_relationships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('agent_id');
            $table->uuid('customer_id');
            $table->uuid('registered_by');
            $table->timestampTz('registered_at')->default(DB::raw('now()'));
        });

        DB::connection('platform')->statement(
            'ALTER TABLE agent.agent_customer_relationships ADD CONSTRAINT acr_agent_id_fk FOREIGN KEY (agent_id) REFERENCES agent.agents(id)'
        );

        // Customer FK is DEFERRABLE: identity.users may be inserted in same transaction
        DB::connection('platform')->statement(
            'ALTER TABLE agent.agent_customer_relationships ADD CONSTRAINT acr_customer_id_fk FOREIGN KEY (customer_id) REFERENCES identity.users(id) DEFERRABLE INITIALLY DEFERRED'
        );

        DB::connection('platform')->statement(
            'CREATE UNIQUE INDEX acr_agent_customer_unique_idx ON agent.agent_customer_relationships (agent_id, customer_id)'
        );

        DB::connection('platform')->statement(
            'CREATE INDEX acr_customer_id_idx ON agent.agent_customer_relationships (customer_id)'
        );

        DB::connection('platform')->statement(
            'CREATE INDEX acr_agent_id_idx ON agent.agent_customer_relationships (agent_id)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('agent.agent_customer_relationships');
    }
};
