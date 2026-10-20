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
        Schema::connection('platform')->create('agent.agent_status_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('agent_id');
            $table->string('previous_status', 20)->nullable();
            $table->string('new_status', 20);
            $table->uuid('actor_id');
            $table->string('actor_type', 20);
            $table->text('reason')->nullable();
            $table->timestampTz('occurred_at')->default(DB::raw('now()'));
        });

        DB::connection('platform')->statement(
            "ALTER TABLE agent.agent_status_history ADD CONSTRAINT ash_actor_type_check CHECK (actor_type IN ('admin','system'))"
        );

        DB::connection('platform')->statement(
            'ALTER TABLE agent.agent_status_history ADD CONSTRAINT ash_agent_id_fk FOREIGN KEY (agent_id) REFERENCES agent.agents(id)'
        );

        DB::connection('platform')->statement(
            'CREATE INDEX ash_agent_id_occurred_at_idx ON agent.agent_status_history (agent_id, occurred_at DESC)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('agent.agent_status_history');
    }
};
