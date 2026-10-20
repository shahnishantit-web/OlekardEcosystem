<?php

declare(strict_types=1);

namespace Tests\Unit\Commission;

use App\Modules\Agent\Domain\Enums\AgentStatus;
use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\Commission\Application\Services\CommissionCalculationService;
use App\Modules\Commission\Domain\Enums\CommissionStatus;
use App\Modules\Commission\Infrastructure\Models\CommissionModel;
use App\Modules\Commission\Infrastructure\Models\CommissionRuleModel;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\Contracts\ProcessedEventRepositoryInterface;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class TierBasedCommissionTest extends TestCase
{
    use DatabaseTransactions;

    private CommissionCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $ledger = $this->createMock(LedgerModuleContract::class);
        $ledger->method('registerAccount');
        $ledger->method('postEntry')->willReturn('journal-tier');

        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('get')->willReturn('commission_expense:BWP');

        $this->service = new CommissionCalculationService(
            $this->createMock(ProcessedEventRepositoryInterface::class),
            $ledger,
            $config,
            $this->createMock(AuditServiceInterface::class),
            $this->createMock(EventPublisherInterface::class),
            $this->createMock(MetricsCollectorInterface::class),
        );
    }

    private function globalRule(int $rate = 200): CommissionRuleModel
    {
        return CommissionRuleModel::factory()->create([
            'rule_type'  => 'flat',
            'rate'       => $rate,
            'applies_to' => 'cash_in',
            'status'     => 'active',
            'valid_from' => now()->subDay()->toDateString(),
            'agent_tier' => null,
        ]);
    }

    private function tierRule(string $tier, int $rate = 300, ?int $volumeThreshold = null): CommissionRuleModel
    {
        return CommissionRuleModel::factory()->create([
            'rule_type'        => 'flat',
            'rate'             => $rate,
            'applies_to'       => 'cash_in',
            'status'           => 'active',
            'valid_from'       => now()->subDay()->toDateString(),
            'agent_tier'       => $tier,
            'volume_threshold' => $volumeThreshold,
        ]);
    }

    public function test_tiered_agent_uses_tier_rule(): void
    {
        $agent = AgentModel::factory()->create(['status' => AgentStatus::Active->value, 'agent_tier' => 'premium']);
        $this->globalRule(200);
        $this->tierRule('premium', 300);

        $result = $this->service->calculate($agent->id, 'txn-tier-1', 'cash_in', 50000, 'BWP', 'wallet-1', 'corr-1');

        $this->assertNotNull($result);
        $this->assertEquals(300, $result->amount);
    }

    public function test_untiered_agent_uses_global_rule(): void
    {
        $agent = AgentModel::factory()->create(['status' => AgentStatus::Active->value, 'agent_tier' => null]);
        $this->globalRule(200);
        $this->tierRule('premium', 300);

        $result = $this->service->calculate($agent->id, 'txn-tier-2', 'cash_in', 50000, 'BWP', 'wallet-1', 'corr-2');

        $this->assertNotNull($result);
        $this->assertEquals(200, $result->amount);
    }

    public function test_multiple_tier_rules_picks_highest_applicable_volume_threshold(): void
    {
        $agent = AgentModel::factory()->create(['status' => AgentStatus::Active->value, 'agent_tier' => 'gold']);

        CommissionModel::factory()->create([
            'agent_id'      => $agent->id,
            'amount'        => 5000000,
            'currency_code' => 'BWP',
            'status'        => CommissionStatus::Pending->value,
            'created_at'    => now()->startOfMonth()->addHour(),
        ]);

        $this->tierRule('gold', rate: 200, volumeThreshold: 1000000);
        $this->tierRule('gold', rate: 300, volumeThreshold: 3000000);
        $this->tierRule('gold', rate: 400, volumeThreshold: 8000000);

        $result = $this->service->calculate($agent->id, 'txn-vol-1', 'cash_in', 50000, 'BWP', 'wallet-1', 'corr-3');

        $this->assertNotNull($result);
        $this->assertEquals(300, $result->amount);
    }

    public function test_null_tier_reverts_to_global_rule(): void
    {
        $agent = AgentModel::factory()->create(['status' => AgentStatus::Active->value, 'agent_tier' => null]);
        $this->globalRule(200);

        $result = $this->service->calculate($agent->id, 'txn-no-tier', 'cash_in', 50000, 'BWP', 'wallet-1', 'corr-4');

        $this->assertNotNull($result);
        $this->assertEquals(200, $result->amount);
    }
}
