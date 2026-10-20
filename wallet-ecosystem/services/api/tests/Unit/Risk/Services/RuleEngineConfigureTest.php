<?php

declare(strict_types=1);

namespace Tests\Unit\Risk\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Risk\Application\Commands\ConfigureRiskRuleCommand;
use App\Modules\Risk\Application\Services\RuleEngineService;
use App\Modules\Risk\Application\Services\VelocityAccumulatorService;
use App\Modules\Risk\Domain\Enums\RuleType;
use App\Modules\Risk\Domain\Models\RiskRule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RuleEngineConfigureTest extends TestCase
{
    use DatabaseTransactions;

    private RuleEngineService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $velocity = $this->createStub(VelocityAccumulatorService::class);
        $config   = $this->createStub(ConfigurationServiceInterface::class);
        $config->method('getOrDefault')->willReturnArgument(1);
        $audit = $this->createStub(AuditServiceInterface::class);

        $this->service = new RuleEngineService($velocity, $config, $audit);
    }

    private function cmd(array $overrides = []): ConfigureRiskRuleCommand
    {
        return new ConfigureRiskRuleCommand(
            ruleType:        $overrides['ruleType']        ?? RuleType::AmountLimit,
            appliesToType:   $overrides['appliesToType']   ?? 'global',
            appliesToId:     $overrides['appliesToId']     ?? null,
            thresholdAmount: $overrides['thresholdAmount'] ?? '1000.00',
            action:          $overrides['action']          ?? 'block',
            actorId:         $overrides['actorId']         ?? Str::uuid()->toString(),
        );
    }

    /** Creating a new rule inserts a record */
    public function test_new_rule_is_created(): void
    {
        $rule = $this->service->configureRule($this->cmd());

        $this->assertNotNull($rule->id);
        $this->assertTrue($rule->is_active);
        $this->assertDatabaseHas('risk.risk_rules', ['id' => $rule->id, 'is_active' => true], 'risk');
    }

    /** Configuring same name+subject_id deactivates old and creates new */
    public function test_existing_rule_deactivated_on_update(): void
    {
        $cmd = $this->cmd(['appliesToType' => 'global']);

        $first = $this->service->configureRule($cmd);
        $this->assertTrue($first->is_active);

        $this->service->configureRule($cmd);

        $first->refresh();
        $this->assertFalse($first->is_active, 'Original rule should be deactivated');

        $this->assertDatabaseCount('risk.risk_rules', 2, 'risk');
    }

    /** Subject-scoped rule with matching subject_id overrides global */
    public function test_subject_scoped_rule_independent_of_global(): void
    {
        $customerId = Str::uuid()->toString();

        $global   = $this->service->configureRule($this->cmd(['appliesToType' => 'global', 'thresholdAmount' => '1000.00']));
        $customer = $this->service->configureRule($this->cmd(['appliesToType' => 'customer', 'appliesToId' => $customerId, 'thresholdAmount' => '500.00']));

        $this->assertTrue($global->is_active);
        $this->assertTrue($customer->is_active);
        $this->assertDatabaseCount('risk.risk_rules', 2, 'risk');
    }

    /** Rule name is derived deterministically from type + applies_to + window */
    public function test_rule_name_derived_correctly(): void
    {
        $rule = $this->service->configureRule(new ConfigureRiskRuleCommand(
            ruleType:      RuleType::VelocityAmount,
            appliesToType: 'global',
            windowSeconds: 86400,
            action:        'block',
        ));

        $this->assertSame('velocity_amount.global.daily', $rule->name);
    }

    /** Global rule and customer rule with same type coexist (different subject_id) */
    public function test_global_and_customer_rules_coexist(): void
    {
        $customerId = Str::uuid()->toString();

        $global   = $this->service->configureRule($this->cmd(['appliesToType' => 'global']));
        $customer = $this->service->configureRule($this->cmd(['appliesToType' => 'customer', 'appliesToId' => $customerId]));

        $this->assertTrue($global->is_active);
        $this->assertTrue($customer->is_active);
        $this->assertNotSame($global->id, $customer->id);
    }

    /** Updating customer rule does not affect global rule */
    public function test_update_customer_rule_does_not_affect_global(): void
    {
        $customerId = Str::uuid()->toString();

        $global = $this->service->configureRule($this->cmd(['appliesToType' => 'global']));
        $this->service->configureRule($this->cmd(['appliesToType' => 'customer', 'appliesToId' => $customerId]));
        $this->service->configureRule($this->cmd(['appliesToType' => 'customer', 'appliesToId' => $customerId, 'thresholdAmount' => '500.00']));

        $global->refresh();
        $this->assertTrue($global->is_active, 'Global rule must remain active after customer rule update');
    }
}
