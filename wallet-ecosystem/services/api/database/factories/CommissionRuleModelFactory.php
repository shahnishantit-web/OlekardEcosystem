<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Commission\Domain\Enums\CommissionAppliesTo;
use App\Modules\Commission\Domain\Enums\CommissionRuleStatus;
use App\Modules\Commission\Domain\Enums\CommissionRuleType;
use App\Modules\Commission\Infrastructure\Models\CommissionRuleModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Ramsey\Uuid\Uuid;

/**
 * @extends Factory<CommissionRuleModel>
 */
final class CommissionRuleModelFactory extends Factory
{
    protected $model = CommissionRuleModel::class;

    public function definition(): array
    {
        return [
            'id'               => (string) Uuid::uuid7(),
            'name'             => 'Test Rule ' . fake()->word(),
            'rule_type'        => CommissionRuleType::Flat->value,
            'rate'             => 200,
            'applies_to'       => CommissionAppliesTo::CashIn->value,
            'agent_tier'       => null,
            'status'           => CommissionRuleStatus::Active->value,
            'valid_from'       => now()->toDateString(),
            'valid_to'         => null,
            'volume_threshold' => null,
            'min_amount'       => null,
            'max_amount'       => null,
            'created_by'       => (string) Uuid::uuid7(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ];
    }
}
