<?php

declare(strict_types=1);

namespace Tests\Unit\Commission;

use App\Modules\Agent\Domain\Enums\AgentStatus;
use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\AgentFloat\Infrastructure\Models\AgentFloatWalletModel;
use App\Modules\Commission\Application\Services\CommissionPayoutService;
use App\Modules\Commission\Domain\Enums\CommissionPayoutStatus;
use App\Modules\Commission\Domain\Enums\CommissionStatus;
use App\Modules\Commission\Domain\Events\CommissionPayoutDeferredEvent;
use App\Modules\Commission\Domain\Exceptions\CommissionAlreadyProcessedException;
use App\Modules\Commission\Infrastructure\Models\CommissionModel;
use App\Modules\Commission\Infrastructure\Models\CommissionPayoutModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class CommissionPayoutServiceTest extends TestCase
{
    use DatabaseTransactions;

    private CommissionPayoutService $service;
    private LedgerModuleContract $ledger;
    private EventPublisherInterface $events;
    private AuditServiceInterface $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger  = $this->createMock(LedgerModuleContract::class);
        $this->events  = $this->createMock(EventPublisherInterface::class);
        $this->audit   = $this->createMock(AuditServiceInterface::class);

        $this->service = new CommissionPayoutService($this->ledger, $this->audit, $this->events, $this->createMock(MetricsCollectorInterface::class));
    }

    private function makeActiveAgentWithFloat(): array
    {
        $agent = AgentModel::factory()->create(['status' => AgentStatus::Active->value]);
        AgentFloatWalletModel::factory()->create([
            'agent_id' => $agent->id,
            'ledger_account_reference' => "agent_float:{$agent->id}",
        ]);
        return [$agent, CommissionModel::factory()->count(2)->create([
            'agent_id'      => $agent->id,
            'amount'        => 200,
            'status'        => CommissionStatus::Pending->value,
            'currency_code' => 'BWP',
        ])];
    }

    public function test_active_agent_results_in_completed_payout(): void
    {
        [$agent, $commissions] = $this->makeActiveAgentWithFloat();

        $this->ledger->method('postEntry')->willReturn('journal-payout-1');
        $this->audit->method('record');
        $this->events->method('publish');

        $payout = $this->service->approveAndExecute(
            'admin-1', $agent->id, $commissions->pluck('id')->all(), 'corr-1'
        );

        $this->assertEquals(CommissionPayoutStatus::Completed, $payout->status);
        $this->assertEquals(400, $payout->total_amount);
        $this->assertEquals('journal-payout-1', $payout->journal_id);
        $this->assertEquals(0, CommissionModel::where('status', CommissionStatus::Pending->value)->count());
    }

    public function test_suspended_agent_results_in_deferred_payout(): void
    {
        $agent = AgentModel::factory()->create(['status' => AgentStatus::Suspended->value]);
        $commissions = CommissionModel::factory()->count(2)->create([
            'agent_id' => $agent->id,
            'amount'   => 200,
            'status'   => CommissionStatus::Pending->value,
            'currency_code' => 'BWP',
        ]);

        $this->events->expects($this->once())->method('publish')
            ->with($this->isInstanceOf(CommissionPayoutDeferredEvent::class));

        $payout = $this->service->approveAndExecute(
            'admin-1', $agent->id, $commissions->pluck('id')->all(), 'corr-2'
        );

        $this->assertEquals(CommissionPayoutStatus::Deferred, $payout->status);
        $this->assertEquals(
            CommissionStatus::Approved,
            CommissionModel::find($commissions->first()->id)->status
        );
    }

    public function test_commission_not_pending_throws_already_processed(): void
    {
        $agent = AgentModel::factory()->create(['status' => AgentStatus::Active->value]);
        $commission = CommissionModel::factory()->create([
            'agent_id' => $agent->id,
            'status'   => CommissionStatus::Paid->value,
        ]);

        $this->expectException(CommissionAlreadyProcessedException::class);

        $this->service->approveAndExecute('admin-1', $agent->id, [$commission->id], 'corr-3');
    }

    public function test_ledger_failure_transitions_payout_to_failed(): void
    {
        [$agent, $commissions] = $this->makeActiveAgentWithFloat();

        $this->ledger->method('postEntry')->willThrowException(new \RuntimeException('Ledger down'));
        $this->audit->method('record');
        $this->events->method('publish');

        $payout = $this->service->approveAndExecute(
            'admin-1', $agent->id, $commissions->pluck('id')->all(), 'corr-4'
        );

        $this->assertEquals(CommissionPayoutStatus::Failed, $payout->status);
    }

    public function test_cancel_deferred_payout_reverts_commissions_to_pending(): void
    {
        $agent = AgentModel::factory()->create(['status' => AgentStatus::Suspended->value]);
        $payout = CommissionPayoutModel::factory()->create([
            'agent_id' => $agent->id,
            'status'   => CommissionPayoutStatus::Deferred->value,
        ]);
        CommissionModel::factory()->count(2)->create([
            'agent_id'  => $agent->id,
            'payout_id' => $payout->id,
            'status'    => CommissionStatus::Approved->value,
        ]);

        $this->audit->method('record');
        $this->events->method('publish');

        $cancelled = $this->service->cancel($payout->id, 'admin-1', 'corr-5');

        $this->assertEquals(CommissionPayoutStatus::Cancelled, $cancelled->status);
        $this->assertEquals(
            2,
            CommissionModel::where('status', CommissionStatus::Pending->value)->where('payout_id', null)->count()
        );
    }
}
