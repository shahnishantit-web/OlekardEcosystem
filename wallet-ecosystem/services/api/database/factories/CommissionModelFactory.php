<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Commission\Domain\Enums\CommissionAppliesTo;
use App\Modules\Commission\Domain\Enums\CommissionStatus;
use App\Modules\Commission\Infrastructure\Models\CommissionModel;
use App\Modules\Commission\Infrastructure\Models\CommissionRuleModel;
use App\Modules\Agent\Infrastructure\Models\AgentModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Ramsey\Uuid\Uuid;

/**
 * @extends Factory<CommissionModel>
 */
final class CommissionModelFactory extends Factory
{
    protected $model = CommissionModel::class;

    public function definition(): array
    {
        return [
            'id'               => (string) Uuid::uuid7(),
            'agent_id'         => fn () => AgentModel::factory()->create()->id,
            'transaction_id'   => (string) Uuid::uuid7(),
            'transaction_type' => CommissionAppliesTo::CashIn->value,
            'amount'           => 200,
            'currency_code'    => 'BWP',
            'rule_id'          => fn () => CommissionRuleModel::factory()->create()->id,
            'status'           => CommissionStatus::Pending->value,
            'payout_id'        => null,
            'journal_id'       => (string) Uuid::uuid7(),
            'idempotency_key'  => (string) Uuid::uuid7(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ];
    }
}
