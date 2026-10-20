<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Commission\Domain\Enums\CommissionPayoutStatus;
use App\Modules\Commission\Infrastructure\Models\CommissionPayoutModel;
use App\Modules\Agent\Infrastructure\Models\AgentModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Ramsey\Uuid\Uuid;

/**
 * @extends Factory<CommissionPayoutModel>
 */
final class CommissionPayoutModelFactory extends Factory
{
    protected $model = CommissionPayoutModel::class;

    public function definition(): array
    {
        return [
            'id'              => (string) Uuid::uuid7(),
            'agent_id'        => fn () => AgentModel::factory()->create()->id,
            'total_amount'    => 400,
            'currency_code'   => 'BWP',
            'status'          => CommissionPayoutStatus::Approved->value,
            'approved_by'     => (string) Uuid::uuid7(),
            'approved_at'     => now(),
            'executed_at'     => null,
            'cancelled_by'    => null,
            'cancelled_at'    => null,
            'journal_id'      => null,
            'idempotency_key' => (string) Uuid::uuid7(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ];
    }
}
