<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\AgentFloat\Infrastructure\Models\AgentFloatWalletModel;
use App\Modules\Agent\Infrastructure\Models\AgentModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Ramsey\Uuid\Uuid;

/**
 * @extends Factory<AgentFloatWalletModel>
 */
final class AgentFloatWalletModelFactory extends Factory
{
    protected $model = AgentFloatWalletModel::class;

    public function definition(): array
    {
        return [
            'id'                       => (string) Uuid::uuid7(),
            'agent_id'                 => fn () => AgentModel::factory()->create()->id,
            'wallet_id'                => (string) Uuid::uuid7(),
            'ledger_account_reference' => 'agent_float_' . (string) Uuid::uuid7(),
            'created_at'               => now(),
            'updated_at'               => now(),
        ];
    }
}
