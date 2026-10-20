<?php

declare(strict_types=1);

namespace Tests\Unit\Risk\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Risk\Application\Services\RuleEngineService;
use App\Modules\Risk\Application\Services\VelocityAccumulatorService;
use App\Modules\Risk\Domain\Enums\RiskDecisionEnum;
use App\Modules\Risk\Domain\Enums\RuleType;
use App\Modules\Risk\Domain\Models\RiskRule;
use App\Modules\Risk\Domain\ValueObjects\TransactionRiskContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RuleEngineServiceTest extends TestCase
{
    use DatabaseTransactions;

    private RuleEngineService $service;
    private VelocityAccumulatorService $velocity;
    private ConfigurationServiceInterface $config;
    private AuditServiceInterface $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->velocity = $this->createMock(VelocityAccumulatorService::class);
        $this->config   = $this->createMock(ConfigurationServiceInterface::class);
        $this->audit    = $this->createStub(AuditServiceInterface::class);

        $this->config->method('getOrDefault')
            ->willReturnCallback(function (string $key, mixed $default) {
                return match ($key) {
                    'risk.block_score_threshold'  => 85,
                    'risk.review_score_threshold' => 50,
                    default                       => $default,
                };
            });

        $this->service = new RuleEngineService($this->velocity, $this->config, $this->audit);
    }

    private function makeContext(string $amount = '100.00', bool $isNewDevice = false): TransactionRiskContext
    {
        return new TransactionRiskContext(
            idempotencyKey:       Str::uuid()->toString(),
            transactionReference: 'TXN-TEST-001',
            transactionType:      'transfer',
            amount:               $amount,
            currency:             'BWP',
            customerId:           Str::uuid()->toString(),
            isNewDevice:          $isNewDevice,
            isNewBeneficiary:     false,
        );
    }

    private function makeRule(
        string $ruleType,
        ?float $thresholdAmount = null,
        ?int $thresholdCount = null,
        ?int $windowSeconds = null,
        float $riskScoreWeight = 1.0,
        string $action = 'block',
    ): RiskRule {
        return RiskRule::create([
            'id'               => Str::uuid()->toString(),
            'name'             => 'test.'.$ruleType.'.'.Str::random(6),
            'rule_type'        => $ruleType,
            'applies_to'       => 'global',
            'threshold_amount' => $thresholdAmount,
            'threshold_count'  => $thresholdCount,
            'window_seconds'   => $windowSeconds,
            'risk_score_weight' => $riskScoreWeight,
            'action'           => $action,
            'is_active'        => true,
            'effective_from'   => now()->subDay(),
            'created_by'       => (string) \Illuminate\Support\Str::uuid(),
            'updated_by'       => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    public function test_empty_rules_returns_allow(): void
    {
        $result = $this->service->applyRules($this->makeContext(), []);

        $this->assertSame(0.0, $result['overallScore']);
        $this->assertSame(RiskDecisionEnum::Allow, $result['decision']);
        $this->assertEmpty($result['rulesApplied']);
    }

    public function test_amount_below_limit_returns_allow(): void
    {
        $rule   = $this->makeRule(RuleType::AmountLimit->value, thresholdAmount: 1000.00);
        $result = $this->service->applyRules($this->makeContext('500.00'), [$rule]);

        $applied = $result['rulesApplied'][0];
        $this->assertFalse($applied['triggered']);
        $this->assertSame(RiskDecisionEnum::Allow, $result['decision']);
    }

    public function test_amount_above_limit_triggers_block(): void
    {
        $rule   = $this->makeRule(RuleType::AmountLimit->value, thresholdAmount: 500.00, riskScoreWeight: 5.0);
        $result = $this->service->applyRules($this->makeContext('1000.00'), [$rule]);

        $applied = $result['rulesApplied'][0];
        $this->assertTrue($applied['triggered']);
        $this->assertGreaterThanOrEqual(85.0, $result['overallScore']);
        $this->assertSame(RiskDecisionEnum::Block, $result['decision']);
    }

    public function test_velocity_count_exceeded_returns_review(): void
    {
        $ctx  = $this->makeContext('100.00');
        $rule = $this->makeRule(
            RuleType::VelocityCount->value,
            thresholdCount: 5,
            windowSeconds:  86400,
            riskScoreWeight: 3.0,
        );

        $this->velocity->method('buildKey')->willReturn('velocity:test:transfer:86400');
        $this->velocity->method('getCount')->willReturn(6);

        $result = $this->service->applyRules($ctx, [$rule]);

        $applied = $result['rulesApplied'][0];
        $this->assertTrue($applied['triggered']);
        $this->assertGreaterThanOrEqual(50.0, $result['overallScore']);
        $this->assertLessThan(85.0, $result['overallScore']);
        $this->assertSame(RiskDecisionEnum::Review, $result['decision']);
    }

    public function test_velocity_count_not_exceeded_returns_allow(): void
    {
        $ctx  = $this->makeContext('100.00');
        $rule = $this->makeRule(RuleType::VelocityCount->value, thresholdCount: 5, windowSeconds: 86400);

        $this->velocity->method('buildKey')->willReturn('velocity:test:transfer:86400');
        $this->velocity->method('getCount')->willReturn(3);

        $result = $this->service->applyRules($ctx, [$rule]);

        $this->assertFalse($result['rulesApplied'][0]['triggered']);
        $this->assertSame(RiskDecisionEnum::Allow, $result['decision']);
    }

    public function test_score_aggregation_across_multiple_rules(): void
    {
        $ctx   = $this->makeContext('100.00');
        $rule1 = $this->makeRule(RuleType::AmountLimit->value, thresholdAmount: 50.00, riskScoreWeight: 1.0);
        $rule2 = $this->makeRule(RuleType::AmountLimit->value, thresholdAmount: 90.00, riskScoreWeight: 1.0);

        // 100.00 > 50.00 triggers rule1 (score += 20)
        // 100.00 > 90.00 triggers rule2 (score += 20)
        $result = $this->service->applyRules($ctx, [$rule1, $rule2]);

        $this->assertTrue($result['rulesApplied'][0]['triggered']);
        $this->assertTrue($result['rulesApplied'][1]['triggered']);
        $this->assertSame(40.0, $result['overallScore']);
        $this->assertSame(RiskDecisionEnum::Allow, $result['decision']); // 40 < 50 threshold
    }

    public function test_new_device_flag_triggers_device_score_rule(): void
    {
        $ctx  = $this->makeContext('100.00', isNewDevice: true);
        $rule = $this->makeRule(RuleType::DeviceScore->value, thresholdCount: 1, riskScoreWeight: 1.0);

        $result = $this->service->applyRules($ctx, [$rule]);

        $this->assertTrue($result['rulesApplied'][0]['triggered']);
    }

    public function test_not_new_device_does_not_trigger_device_score_rule(): void
    {
        $ctx  = $this->makeContext('100.00', isNewDevice: false);
        $rule = $this->makeRule(RuleType::DeviceScore->value, thresholdCount: 1, riskScoreWeight: 1.0);

        $result = $this->service->applyRules($ctx, [$rule]);

        $this->assertFalse($result['rulesApplied'][0]['triggered']);
    }

    public function test_score_capped_at_100(): void
    {
        $ctx   = $this->makeContext('10000.00');
        $rules = [];

        for ($i = 0; $i < 10; $i++) {
            $rules[] = $this->makeRule(RuleType::AmountLimit->value, thresholdAmount: 1.00, riskScoreWeight: 5.0);
        }

        $result = $this->service->applyRules($ctx, $rules);

        $this->assertSame(100.0, $result['overallScore']);
        $this->assertSame(RiskDecisionEnum::Block, $result['decision']);
    }
}
