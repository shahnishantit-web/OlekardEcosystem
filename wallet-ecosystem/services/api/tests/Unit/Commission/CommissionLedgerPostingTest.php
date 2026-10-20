<?php

declare(strict_types=1);

namespace Tests\Unit\Commission;

use App\Modules\Agent\Domain\Enums\AgentStatus;
use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\Commission\Application\Services\CommissionCalculationService;
use App\Modules\Commission\Infrastructure\Models\CommissionRuleModel;
use App\Modules\Platform\Domain\Contracts\ProcessedEventRepositoryInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class CommissionLedgerPostingTest extends TestCase
{
    use DatabaseTransactions;

    private LedgerModuleContract $ledger;
    private CommissionCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = $this->createMock(LedgerModuleContract::class);
        $config       = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('get')->willReturn('commission_expense:BWP');

        $audit  = $this->createMock(AuditServiceInterface::class);
        $events = $this->createMock(EventPublisherInterface::class);
        $events->method('publish');

        $this->service = new CommissionCalculationService(
            $this->createMock(ProcessedEventRepositoryInterface::class),
            $this->ledger,
            $config,
            $audit,
            $events,
            $this->createMock(MetricsCollectorInterface::class),
        );
    }

    public function test_accrual_journal_has_correct_debit_and_credit_lines(): void
    {
        $agent = AgentModel::factory()->create(['status' => AgentStatus::Active->value, 'agent_tier' => null]);
        CommissionRuleModel::factory()->create([
            'rule_type'  => 'flat',
            'rate'       => 200,
            'applies_to' => 'cash_in',
            'status'     => 'active',
            'valid_from' => now()->subDay()->toDateString(),
            'valid_to'   => null,
            'agent_tier' => null,
        ]);

        $this->ledger->method('registerAccount');

        $this->ledger->expects($this->once())
            ->method('postEntry')
            ->with($this->callback(function (array $request) use ($agent) {
                $this->assertEquals('commission', $request['entry_type']);
                $this->assertEquals('BWP', $request['currency_code']);

                $lines = $request['lines'];
                $this->assertCount(2, $lines);

                $debitLine  = collect($lines)->firstWhere('direction', 'debit');
                $creditLine = collect($lines)->firstWhere('direction', 'credit');

                $this->assertEquals('commission_expense:BWP', $debitLine['ledger_account_reference']);
                $this->assertEquals("commission_payable:{$agent->id}", $creditLine['ledger_account_reference']);
                $this->assertEquals(200, $debitLine['amount']);
                $this->assertEquals(200, $creditLine['amount']);

                return true;
            }))
            ->willReturn('journal-id-5');

        $commission = $this->service->calculate($agent->id, 'txn-journal', 'cash_in', 50000, 'BWP', 'wallet-1', 'corr-10');

        $this->assertNotNull($commission);
        $this->assertEquals('journal-id-5', $commission->journal_id);
    }
}
