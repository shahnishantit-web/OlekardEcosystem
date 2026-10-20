<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Fees;

use App\Modules\Fees\Application\Services\FeeCalculationService;
use App\Modules\Fees\Domain\Enums\FeeRuleStatus;
use App\Modules\Fees\Domain\Enums\FeeSubjectType;
use App\Modules\Fees\Domain\Enums\FeeTransactionType;
use App\Modules\Fees\Domain\Enums\FeeType;
use App\Modules\Fees\Infrastructure\Models\FeeRuleModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FeeCalculationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private FeeCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FeeCalculationService();
    }

    // ── T026: calculatePercentageFee ──────────────────────────────────────────

    public function test_percentage_fee_bwp_1000_at_0_5_percent(): void
    {
        $result = $this->service->calculatePercentageFee(1000_00, 0.5, null, null, 'rule-1');

        $this->assertSame(500, $result->feeAmountMinor);
        $this->assertTrue($result->hasApplicableRule);
        $this->assertSame(FeeType::Percentage->value, $result->feeType);
        $this->assertFalse($result->minFeeApplied);
        $this->assertFalse($result->maxFeeApplied);
    }

    public function test_percentage_fee_rounds_correctly(): void
    {
        // BWP 7 (700 minor units) at 0.5% = 3.5 → rounds up to 4 (half-up)
        $result = $this->service->calculatePercentageFee(700, 0.5, null, null, 'rule-1');

        $this->assertSame(4, $result->feeAmountMinor);
    }

    public function test_percentage_fee_no_float_drift_at_large_amount(): void
    {
        // 999_999_999 at 0.5% = 4_999_999.995 → rounds to 5_000_000
        // bcmath must produce exactly 5_000_000 (no IEEE 754 drift)
        $result = $this->service->calculatePercentageFee(999_999_999, 0.5, null, null, 'rule-1');

        $this->assertSame(5_000_000, $result->feeAmountMinor);
    }

    // ── T027: calculateFixedFee ───────────────────────────────────────────────

    public function test_fixed_fee_returns_rule_amount_regardless_of_transaction(): void
    {
        $result = $this->service->calculateFixedFee(150, 'rule-2');

        $this->assertSame(150, $result->feeAmountMinor);
        $this->assertTrue($result->hasApplicableRule);
        $this->assertSame(FeeType::Fixed->value, $result->feeType);
    }

    public function test_fixed_fee_same_output_for_large_transaction(): void
    {
        $result = $this->service->calculateFixedFee(150, 'rule-2');

        $this->assertSame(150, $result->feeAmountMinor);
    }

    // ── T053: min floor ───────────────────────────────────────────────────────

    public function test_min_floor_applied_when_calculated_is_below_floor(): void
    {
        // 1000 * 0.5% = 5; floor = 100 → floor applies
        $result = $this->service->calculatePercentageFee(1000, 0.5, 100, null, 'rule-3');

        $this->assertSame(100, $result->feeAmountMinor);
        $this->assertTrue($result->minFeeApplied);
        $this->assertFalse($result->maxFeeApplied);
    }

    public function test_min_floor_not_applied_when_calculated_exceeds_floor(): void
    {
        // 100_000 * 0.5% = 500; floor = 100 → 500 > 100, no floor
        $result = $this->service->calculatePercentageFee(100_000, 0.5, 100, null, 'rule-3');

        $this->assertSame(500, $result->feeAmountMinor);
        $this->assertFalse($result->minFeeApplied);
    }

    // ── T054: max ceiling ─────────────────────────────────────────────────────

    public function test_max_ceiling_applied_when_calculated_exceeds_ceiling(): void
    {
        // 5_000_000 * 0.5% = 25_000; ceiling = 5000 → ceiling applies
        $result = $this->service->calculatePercentageFee(5_000_000, 0.5, null, 5000, 'rule-4');

        $this->assertSame(5000, $result->feeAmountMinor);
        $this->assertFalse($result->minFeeApplied);
        $this->assertTrue($result->maxFeeApplied);
    }

    public function test_max_ceiling_not_applied_when_calculated_is_below_ceiling(): void
    {
        // 1000 * 0.5% = 5; ceiling = 5000 → 5 < 5000, no ceiling
        $result = $this->service->calculatePercentageFee(1000, 0.5, null, 5000, 'rule-4');

        $this->assertSame(5, $result->feeAmountMinor);
        $this->assertFalse($result->maxFeeApplied);
    }

    // ── T035: resolveRule ─────────────────────────────────────────────────────

    public function test_agent_rule_overrides_global(): void
    {
        $agentId = (string) Str::uuid();

        $this->seedRule('transfer', FeeType::Percentage, FeeSubjectType::Global, null, 0.5);
        $this->seedRule('transfer', FeeType::Fixed, FeeSubjectType::Agent, $agentId, null, 50);

        $rule = $this->service->resolveRule('transfer', 'BWP', FeeSubjectType::Agent->value, $agentId);

        $this->assertNotNull($rule);
        $this->assertSame(FeeType::Fixed, $rule->fee_type);
        $this->assertSame(50, $rule->amount_minor);
    }

    public function test_customer_rule_overrides_global(): void
    {
        $customerId = (string) Str::uuid();

        $this->seedRule('transfer', FeeType::Percentage, FeeSubjectType::Global, null, 0.5);
        $this->seedRule('transfer', FeeType::Percentage, FeeSubjectType::Customer, $customerId, 0.1);

        $rule = $this->service->resolveRule('transfer', 'BWP', FeeSubjectType::Customer->value, $customerId);

        $this->assertNotNull($rule);
        $this->assertEqualsWithDelta(0.1, $rule->rate, 0.0001);
    }

    public function test_global_fallback_when_no_subject_specific_rule(): void
    {
        $this->seedRule('transfer', FeeType::Percentage, FeeSubjectType::Global, null, 0.5);

        $rule = $this->service->resolveRule('transfer', 'BWP', FeeSubjectType::Customer->value, (string) Str::uuid());

        $this->assertNotNull($rule);
        $this->assertSame(FeeSubjectType::Global, $rule->subject_type);
    }

    public function test_returns_null_when_no_rule_configured(): void
    {
        $rule = $this->service->resolveRule('funding', 'BWP', FeeSubjectType::Global->value, null);

        $this->assertNull($rule);
    }

    public function test_expired_rule_not_returned(): void
    {
        $this->seedRule('withdrawal', FeeType::Fixed, FeeSubjectType::Global, null, null, 100, valid_to: now()->subDay());

        $rule = $this->service->resolveRule('withdrawal', 'BWP', FeeSubjectType::Global->value, null);

        $this->assertNull($rule);
    }

    public function test_not_yet_effective_rule_not_returned(): void
    {
        $this->seedRule('withdrawal', FeeType::Fixed, FeeSubjectType::Global, null, null, 100, valid_from: now()->addDay());

        $rule = $this->service->resolveRule('withdrawal', 'BWP', FeeSubjectType::Global->value, null);

        $this->assertNull($rule);
    }

    // ── T057: agent-specific rule resolution ──────────────────────────────────

    public function test_agent_b_gets_per_agent_override(): void
    {
        $agentB = (string) Str::uuid();
        $agentC = (string) Str::uuid();

        $this->seedRule('cash_in', FeeType::Percentage, FeeSubjectType::Global, null, 1.0);
        $this->seedRule('cash_in', FeeType::Fixed, FeeSubjectType::Agent, $agentB, null, 50);

        $ruleB = $this->service->resolveRule('cash_in', 'BWP', FeeSubjectType::Agent->value, $agentB);
        $ruleC = $this->service->resolveRule('cash_in', 'BWP', FeeSubjectType::Agent->value, $agentC);

        $this->assertSame(FeeType::Fixed, $ruleB?->fee_type);
        $this->assertSame(FeeSubjectType::Global, $ruleC?->subject_type);
    }

    // ── T060: customer-specific rule resolution ───────────────────────────────

    public function test_customer_a_gets_override_customer_b_uses_global(): void
    {
        $customerA = (string) Str::uuid();

        $this->seedRule('transfer', FeeType::Percentage, FeeSubjectType::Global, null, 0.5);
        $this->seedRule('transfer', FeeType::Percentage, FeeSubjectType::Customer, $customerA, 0.1);

        $ruleA = $this->service->resolveRule('transfer', 'BWP', FeeSubjectType::Customer->value, $customerA);
        $ruleB = $this->service->resolveRule('transfer', 'BWP', FeeSubjectType::Customer->value, (string) Str::uuid());

        $this->assertEqualsWithDelta(0.1, $ruleA?->rate, 0.0001);
        $this->assertSame(FeeSubjectType::Global, $ruleB?->subject_type);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seedRule(
        string         $transactionType,
        FeeType        $feeType,
        FeeSubjectType $subjectType,
        ?string        $subjectId,
        ?float         $rate,
        ?int           $amountMinor = null,
        mixed          $valid_from  = null,
        mixed          $valid_to    = null,
    ): FeeRuleModel {
        return FeeRuleModel::create([
            'id'               => (string) Str::orderedUuid(),
            'transaction_type' => $transactionType,
            'fee_type'         => $feeType->value,
            'currency'         => 'BWP',
            'subject_type'     => $subjectType->value,
            'subject_id'       => $subjectId,
            'rate'             => $rate,
            'amount_minor'     => $amountMinor,
            'valid_from'       => $valid_from ?? now()->subDay(),
            'valid_to'         => $valid_to,
            'status'           => FeeRuleStatus::Active->value,
            'created_by'       => 'test',
            'idempotency_key'  => (string) Str::uuid(),
        ]);
    }
}
