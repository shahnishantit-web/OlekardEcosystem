<?php

declare(strict_types=1);

namespace Tests\Unit\Commission;

use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\AgentFloat\Infrastructure\Models\AgentFloatWalletModel;
use App\Modules\Commission\Application\Services\CommissionReversalService;
use App\Modules\Commission\Domain\Enums\CommissionStatus;
use App\Modules\Commission\Domain\Events\CommissionReversedEvent;
use App\Modules\Commission\Domain\Exceptions\CommissionAlreadyReversedException;
use App\Modules\Commission\Infrastructure\Models\CommissionModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class CommissionReversalServiceTest extends TestCase
{
    use DatabaseTransactions;

    private CommissionReversalService $service;
    private LedgerModuleContract $ledger;
    private AuditServiceInterface $audit;
    private EventPublisherInterface $events;
    private MetricsCollectorInterface $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger  = $this->createMock(LedgerModuleContract::class);
        $this->audit   = $this->createMock(AuditServiceInterface::class);
        $this->events  = $this->createMock(EventPublisherInterface::class);
        $this->metrics = $this->createMock(MetricsCollectorInterface::class);
        $this->service = new CommissionReversalService(
            $this->ledger,
            $this->audit,
            $this->events,
            $this->metrics,
        );
    }

    public function test_pending_reversal_posts_one_compensating_journal_and_transitions_to_reversed(): void
    {
        $agent = AgentModel::factory()->create();
        $commission = CommissionModel::factory()->create([
            'agent_id'      => $agent->id,
            'amount'        => 200,
            'currency_code' => 'BWP',
            'status'        => CommissionStatus::Pending->value,
        ]);

        $this->ledger->expects($this->once())->method('postEntry')
            ->with($this->callback(fn ($req) => str_contains($req['transaction_reference'], 'reversal_accrual')))
            ->willReturn('reversal-journal-1');

        $this->audit->expects($this->once())->method('record')
            ->with($this->isInstanceOf(AuditEventData::class));

        $this->events->expects($this->once())->method('publish')
            ->with($this->isInstanceOf(CommissionReversedEvent::class));

        $reversed = $this->service->reverse($commission->id, 'admin-1', 'fraud', 'corr-1');

        $this->assertEquals(CommissionStatus::Reversed, $reversed->status);
    }

    public function test_paid_reversal_posts_two_journals_and_debits_float(): void
    {
        $agent = AgentModel::factory()->create();
        AgentFloatWalletModel::factory()->create([
            'agent_id'                 => $agent->id,
            'ledger_account_reference' => "agent_float:{$agent->id}",
        ]);
        $commission = CommissionModel::factory()->create([
            'agent_id'      => $agent->id,
            'amount'        => 200,
            'currency_code' => 'BWP',
            'status'        => CommissionStatus::Paid->value,
        ]);

        $callCount = 0;
        $this->ledger->expects($this->exactly(2))->method('postEntry')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return "reversal-journal-{$callCount}";
            });

        $this->events->method('publish');
        $this->audit->method('record');

        $reversed = $this->service->reverse($commission->id, 'admin-1', 'error', 'corr-2');

        $this->assertEquals(CommissionStatus::Reversed, $reversed->status);
    }

    public function test_double_reverse_throws_commission_already_reversed(): void
    {
        $agent = AgentModel::factory()->create();
        AgentFloatWalletModel::factory()->create([
            'agent_id'                 => $agent->id,
            'ledger_account_reference' => "agent_float:{$agent->id}",
        ]);
        $commission = CommissionModel::factory()->create([
            'agent_id' => $agent->id,
            'status'   => CommissionStatus::Pending->value,
        ]);

        $this->ledger->method('postEntry')->willReturn('reversal-j1');
        $this->events->method('publish');
        $this->audit->method('record');

        $this->service->reverse($commission->id, 'admin-1', 'reason-1', 'corr-3');

        $this->expectException(CommissionAlreadyReversedException::class);
        $this->service->reverse($commission->id, 'admin-1', 'reason-2', 'corr-4');
    }

    public function test_audit_log_called_with_reason(): void
    {
        $agent = AgentModel::factory()->create();
        $commission = CommissionModel::factory()->create([
            'agent_id' => $agent->id,
            'status'   => CommissionStatus::Pending->value,
        ]);

        $this->ledger->method('postEntry')->willReturn('j1');
        $this->events->method('publish');

        $capturedAudit = null;
        $this->audit->expects($this->once())->method('record')
            ->with($this->callback(function (AuditEventData $data) use (&$capturedAudit) {
                $capturedAudit = $data;
                return true;
            }));

        $this->service->reverse($commission->id, 'admin-1', 'fraud-detected', 'corr-5');

        $this->assertEquals('fraud-detected', $capturedAudit->metadata['reason']);
        $this->assertEquals('commission.reversed', $capturedAudit->action);
    }
}
