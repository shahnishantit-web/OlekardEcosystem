<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection('platform')->statement(
            "ALTER TABLE agent.agents ADD COLUMN IF NOT EXISTS agent_tier VARCHAR(50) NULL"
        );

        DB::connection('platform')->statement(
            "CREATE INDEX IF NOT EXISTS idx_agents_commission_tier
             ON agent.agents (agent_tier) WHERE agent_tier IS NOT NULL"
        );
    }

    public function down(): void
    {
        DB::connection('platform')->statement(
            "DROP INDEX IF EXISTS idx_agents_commission_tier"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE agent.agents DROP COLUMN IF EXISTS agent_tier"
        );
    }
};
