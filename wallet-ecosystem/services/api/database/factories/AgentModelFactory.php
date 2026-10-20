<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Agent\Domain\Enums\AgentStatus;
use App\Modules\Agent\Domain\Enums\AgentType;
use App\Modules\Agent\Infrastructure\Models\AgentModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * @extends Factory<AgentModel>
 */
final class AgentModelFactory extends Factory
{
    protected $model = AgentModel::class;

    public function definition(): array
    {
        $appId  = (string) Uuid::uuid7();
        $userId = (string) Uuid::uuid7();

        // Insert prerequisite application (non-deferred FK from agent.agents → agent.agent_applications).
        // The application.user_id FK to identity.users is DEFERRABLE INITIALLY DEFERRED, so it is
        // never evaluated during a DatabaseTransactions rollback test.
        DB::connection('platform')->table('agent.agent_applications')->insert([
            'id'             => $appId,
            'user_id'        => $userId,
            'agent_type'     => AgentType::Individual->value,
            'status'         => 'approved',
            'correlation_id' => (string) Uuid::uuid7(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return [
            'id'             => (string) Uuid::uuid7(),
            'application_id' => $appId,
            'user_id'        => $userId,
            'agent_type'     => AgentType::Individual->value,
            'status'         => AgentStatus::Active->value,
            'agent_tier'     => null,
            'approved_by'    => (string) Uuid::uuid7(),
            'approved_at'    => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ];
    }
}
