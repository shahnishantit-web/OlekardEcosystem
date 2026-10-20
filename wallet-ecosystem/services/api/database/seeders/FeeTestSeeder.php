<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Fees\Domain\Enums\FeeRuleStatus;
use App\Modules\Fees\Domain\Enums\FeeSubjectType;
use App\Modules\Fees\Domain\Enums\FeeTransactionType;
use App\Modules\Fees\Domain\Enums\FeeType;
use App\Modules\Fees\Infrastructure\Models\FeeRuleModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class FeeTestSeeder extends Seeder
{
    public function run(): void
    {
        // Global 0.5% transfer rule with min/max bounds
        FeeRuleModel::firstOrCreate(
            [
                'transaction_type' => FeeTransactionType::Transfer->value,
                'fee_type'         => FeeType::Percentage->value,
                'currency'         => 'BWP',
                'subject_type'     => FeeSubjectType::Global->value,
                'subject_id'       => null,
                'status'           => FeeRuleStatus::Active->value,
            ],
            [
                'id'              => Str::orderedUuid()->toString(),
                'rate'            => 0.5,
                'amount_minor'    => null,
                'min_fee_minor'   => 100,
                'max_fee_minor'   => 5000,
                'valid_from'      => now()->subDay(),
                'valid_to'        => null,
                'created_by'      => 'seeder',
                'idempotency_key' => 'seed:transfer-global-0.5pct',
            ]
        );

        // Fixed 150 minor-unit bill_payment rule
        FeeRuleModel::firstOrCreate(
            [
                'transaction_type' => FeeTransactionType::BillPayment->value,
                'fee_type'         => FeeType::Fixed->value,
                'currency'         => 'BWP',
                'subject_type'     => FeeSubjectType::Global->value,
                'subject_id'       => null,
                'status'           => FeeRuleStatus::Active->value,
            ],
            [
                'id'              => Str::orderedUuid()->toString(),
                'rate'            => null,
                'amount_minor'    => 150,
                'min_fee_minor'   => null,
                'max_fee_minor'   => null,
                'valid_from'      => now()->subDay(),
                'valid_to'        => null,
                'created_by'      => 'seeder',
                'idempotency_key' => 'seed:bill-payment-fixed-150',
            ]
        );

        // Customer-specific 0.1% transfer override (exercises US-FEE-006 customer precedence)
        $customerId = config('test.seed_customer_id', Str::uuid()->toString());
        FeeRuleModel::firstOrCreate(
            [
                'transaction_type' => FeeTransactionType::Transfer->value,
                'fee_type'         => FeeType::Percentage->value,
                'currency'         => 'BWP',
                'subject_type'     => FeeSubjectType::Customer->value,
                'subject_id'       => $customerId,
                'status'           => FeeRuleStatus::Active->value,
            ],
            [
                'id'              => Str::orderedUuid()->toString(),
                'rate'            => 0.1,
                'amount_minor'    => null,
                'min_fee_minor'   => null,
                'max_fee_minor'   => null,
                'valid_from'      => now()->subDay(),
                'valid_to'        => null,
                'created_by'      => 'seeder',
                'idempotency_key' => 'seed:transfer-customer-0.1pct',
            ]
        );

        // Per-agent fixed 50 minor-unit cash_in rule (agent ID from PlatformConfigurationSeeder)
        $agentId = config('test.seed_agent_id', Str::uuid()->toString());
        FeeRuleModel::firstOrCreate(
            [
                'transaction_type' => FeeTransactionType::CashIn->value,
                'fee_type'         => FeeType::Fixed->value,
                'currency'         => 'BWP',
                'subject_type'     => FeeSubjectType::Agent->value,
                'subject_id'       => $agentId,
                'status'           => FeeRuleStatus::Active->value,
            ],
            [
                'id'              => Str::orderedUuid()->toString(),
                'rate'            => null,
                'amount_minor'    => 50,
                'min_fee_minor'   => null,
                'max_fee_minor'   => null,
                'valid_from'      => now()->subDay(),
                'valid_to'        => null,
                'created_by'      => 'seeder',
                'idempotency_key' => 'seed:cash-in-agent-fixed-50',
            ]
        );
    }
}
