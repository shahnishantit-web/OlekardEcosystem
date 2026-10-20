<?php

declare(strict_types=1);

namespace Tests\Unit\Commission;

use App\Modules\Agent\Domain\Enums\AgentStatus;
use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\Commission\Application\Services\CommissionCalculationService;
use App\Modules\Commission\Domain\Enums\CommissionStatus;
use App\Modules\Commission\Domain\Events\CommissionCalculatedEvent;
use App\Modules\Commission\Domain\Events\CommissionLedgerPostFailedEvent;
use App\Modules\Commission\Domain\Exceptions\CommissionExpenseAccountNotConfiguredException;
use App\Modules\Commission\Infrastructure\Models\CommissionModel;
use App\Modules\Commission\Infrastructure\Models\CommissionRuleModel;
use App\Modules\Platform\Domain\Contracts\ProcessedEventRepositoryInterface;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class CommissionCalculationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private CommissionCalculationService $service;
    private LedgerModuleContract $ledger;
    private ConfigurationServiceInterface $config;
    private AuditServiceInterface $audit;
    private EventPublisherInterface $events;
    private MetricsCollectorInterface $metrics;
    private ProcessedEventRepositoryInterface $processedEvents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger          = $this->createMock(LedgerModuleContract::class);
        $this->config          = $this->createMock(ConfigurationServiceInterface::class);
        $this->audit           = $this->createMock(AuditServiceInterface::class);
        $this->events          = $this->createMock(EventPublisherInterface::class);
        $this->metrics         = $this->createMock(MetricsCollectorInterface::class);
        $this->processedEvents = $this->createMock(ProcessedEventRepositoryInterface::class);

        $this->service = new CommissionCalculationService(
            $this->processedEvents,
            $this->ledger,
            $this->config,
            $this->audit,
            $this->events,
            $this->metrics,
        );
    }

    private function activeAgent(): AgentModel
    {
        return AgentModel::factory()->create(['status' => AgentStatus::Active->value, 'agent_tier' => null]);
    }

    private function flatRule(string $agentId = null): CommissionRuleModel
    {
        return CommissionRuleModel::factory()->create([
            'rule_type'  => 'flat',
            'rate'       => 200,
            'applies_to' => 'cash_in',
            'status'     => 'active',
            'valid_from' => now()->subDay()->toDateString(),
            'valid_to'   => null,
            'agent_tier' => null,
        ]);
    }

    public function test_flat_rate_calculation_returns_commission(): void
    {
        $agent = $this->activeAgent();
        $this->flatRule();

        $this->config->method('get')->willReturn('commission_expense:BWP');
        $this->ledger->method('registerAccount');
        $this->ledger->method('postEntry')->willReturn('journal-id-1');
        $this->audit->method('record');
        $this->events->method('publish');

        $result = $this->service->calculate($agent->id, 'txn-1', 'cash_in', 50000, 'BWP', 'wallet-1', 'corr-1');

        $this->assertInstanceOf(CommissionModel::class, $result);
        $this->assertEquals(200, $result->amount);
        $this->assertEquals(CommissionStatus::Pending, $result->status);
    }

    public function test_percentage_basis_point_calculation(): void
    {
        $agent = $this->activeAgent();
        CommissionRuleModel::factory()->create([
            'rule_type'  => 'percentage',
            'rate'       => 40,
            'applies_to' => 'cash_out',
            'status'     => 'active',
            'valid_from' => now()->subDay()->toDateString(),
            'valid_to'   => null,
            'agent_tier' => null,
        ]);

        $this->config->method('get')->willReturn('commission_expense:BWP');
        $this->ledger->method('registerAccount');
        $this->ledger->method('postEntry')->willReturn('journal-id-2');
        $this->audit->method('record');
        $this->events->method('publish');

        $result = $this->service->calculate($agent->id, 'txn-2', 'cash_out', 100000, 'BWP', 'wallet-1', 'corr-2');

        $this->assertEquals(400, $result->amount);
    }

    public function test_no_active_rule_returns_null(): void
    {
        $agent = $this->activeAgent();

        $result = $this->service->calculate($agent->id, 'txn-3', 'cash_in', 50000, 'BWP', 'wallet-1', 'corr-3');

        $this->assertNull($result);
    }

    public function test_suspended_agent_returns_null(): void
    {
        $agent = AgentModel::factory()->create(['status' => AgentStatus::Suspended->value]);
        $this->flatRule();

        $result = $this->service->calculate($agent->id, 'txn-4', 'cash_in', 50000, 'BWP', 'wallet-1', 'corr-4');

        $this->assertNull($result);
    }

    public function test_expense_account_not_configured_throws(): void
    {
        $agent = $this->activeAgent();
        $this->flatRule();

        $this->config->method('get')->willReturn(null);
        $this->ledger->method('registerAccount');

        $this->expectException(CommissionExpenseAccountNotConfiguredException::class);

        $this->service->calculate($agent->id, 'txn-5', 'cash_in', 50000, 'BWP', 'wallet-1', 'corr-5');
    }

    public function test_duplicate_transaction_id_returns_existing_without_new_db_write(): void
    {
        $agent = $this->activeAgent();
        $this->flatRule();

        $this->config->method('get')->willReturn('commission_expense:BWP');
        $this->ledger->method('registerAccount');
        $this->ledger->method('postEntry')->willReturn('journal-id-3');
        $this->audit->method('record');
        $this->events->method('publish');

        $first  = $this->service->calculate($agent->id, 'txn-dup', 'cash_in', 50000, 'BWP', 'wallet-1', 'corr-6');
        $second = $this->service->calculate($agent->id, 'txn-dup', 'cash_in', 50000, 'BWP', 'wallet-1', 'corr-7');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, CommissionModel::where('idempotency_key', 'txn-dup:cash_in')->count());
    }

    public function test_ledger_failure_returns_null_and_emits_failed_event(): void
    {
        $agent = $this->activeAgent();
        $this->flatRule();

        $this->config->method('get')->willReturn('commission_expense:BWP');
        $this->ledger->method('registerAccount');
        $this->ledger->method('postEntry')->willThrowException(new \RuntimeException('Ledger unavailable'));

        $this->events->expects($this->once())->method('publish')
            ->with($this->isInstanceOf(CommissionLedgerPostFailedEvent::class));

        $result = $this->service->calculate($agent->id, 'txn-fail', 'cash_in', 50000, 'BWP', 'wallet-1', 'corr-8');

        $this->assertNull($result);
        $this->assertEquals(0, CommissionModel::count());
    }

    public function test_commission_calculated_event_published_on_success(): void
    {
        $agent = $this->activeAgent();
        $this->flatRule();

        $this->config->method('get')->willReturn('commission_expense:BWP');
        $this->ledger->method('registerAccount');
        $this->ledger->method('postEntry')->willReturn('journal-id-4');
        $this->audit->method('record');

        $this->events->expects($this->once())->method('publish')
            ->with($this->isInstanceOf(CommissionCalculatedEvent::class));

        $this->service->calculate($agent->id, 'txn-ok', 'cash_in', 50000, 'BWP', 'wallet-1', 'corr-9');
    }
}
