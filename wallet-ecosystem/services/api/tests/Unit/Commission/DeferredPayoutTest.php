<?php

declare(strict_types=1);

namespace Tests\Unit\Commission;

use App\Modules\Agent\Domain\Enums\AgentStatus;
use App\Modules\Agent\Domain\Events\AgentReactivatedEvent;
use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\AgentFloat\Infrastructure\Models\AgentFloatWalletModel;
use App\Modules\Commission\Application\Services\CommissionPayoutService;
use App\Modules\Commission\Domain\Enums\CommissionPayoutStatus;
use App\Modules\Commission\Domain\Enums\CommissionStatus;
use App\Modules\Commission\Domain\Exceptions\PayoutCancellationNotAllowedException;
use App\Modules\Commission\Infrastructure\Listeners\AgentReactivatedCommissionListener;
use App\Modules\Commission\Infrastructure\Models\CommissionModel;
use App\Modules\Commission\Infrastructure\Models\CommissionPayoutModel;
use App\Modules\Platform\Domain\Contracts\ProcessedEventRepositoryInterface;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class DeferredPayoutTest extends TestCase
{
    use DatabaseTransactions;

    private CommissionPayoutService $service;
    private LedgerModuleContract $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = $this->createMock(LedgerModuleContract::class);
        $this->service = new CommissionPayoutService(
            $this->ledger,
            $this->createMock(AuditServiceInterface::class),
            $this->createMock(EventPublisherInterface::class),
            $this->createMock(MetricsCollectorInterface::class),
        );
    }

    private function makeSuspendedScenario(): array
    {
        $agent = AgentModel::factory()->create(['status' => AgentStatus::Suspended->value]);
        $payout = CommissionPayoutModel::factory()->create([
            'agent_id' => $agent->id,
            'status'   => CommissionPayoutStatus::Deferred->value,
            'total_amount' => 400,
            'currency_code' => 'BWP',
        ]);
        CommissionModel::factory()->count(2)->create([
            'agent_id'  => $agent->id,
            'payout_id' => $payout->id,
            'status'    => CommissionStatus::Approved->value,
            'amount'    => 200,
        ]);
        return [$agent, $payout];
    }

    public function test_suspended_agent_at_approval_produces_deferred(): void
    {
        $agent = AgentModel::factory()->create(['status' => AgentStatus::Suspended->value]);
        $commissions = CommissionModel::factory()->count(2)->create([
            'agent_id' => $agent->id,
            'amount'   => 200,
            'status'   => CommissionStatus::Pending->value,
            'currency_code' => 'BWP',
        ]);

        $payout = $this->service->approveAndExecute('admin-1', $agent->id, $commissions->pluck('id')->all(), 'corr-1');

        $this->assertEquals(CommissionPayoutStatus::Deferred, $payout->status);
    }

    public function test_execute_deferred_completes_payout(): void
    {
        [, $payout] = $this->makeSuspendedScenario();
        AgentFloatWalletModel::factory()->create([
            'agent_id'                 => $payout->agent_id,
            'ledger_account_reference' => "agent_float:{$payout->agent_id}",
        ]);

        $this->ledger->method('postEntry')->willReturn('journal-deferred-1');

        $completed = $this->service->executeDeferred($payout->id);

        $this->assertEquals(CommissionPayoutStatus::Completed, $completed->status);
    }

    public function test_agent_reactivated_listener_calls_execute_deferred(): void
    {
        [, $payout] = $this->makeSuspendedScenario();
        AgentFloatWalletModel::factory()->create([
            'agent_id'                 => $payout->agent_id,
            'ledger_account_reference' => "agent_float:{$payout->agent_id}",
        ]);

        $this->ledger->method('postEntry')->willReturn('journal-reactivated-1');

        $processedRepo = $this->createMock(ProcessedEventRepositoryInterface::class);
        $processedRepo->method('hasBeenProcessed')->willReturn(false);
        $processedRepo->expects($this->once())->method('markAsProcessed');

        $listener = new AgentReactivatedCommissionListener($this->service, $processedRepo);

        $listener->handle(new AgentReactivatedEvent(
            agentId:       $payout->agent_id,
            reactivatedBy: 'admin-1',
            occurredAt:    now()->toIso8601String(),
            correlationId: 'corr-reactivated-1',
        ));

        $payout->refresh();
        $this->assertEquals(CommissionPayoutStatus::Completed, $payout->status);
    }

    public function test_cancel_deferred_reverts_commissions_to_pending(): void
    {
        [, $payout] = $this->makeSuspendedScenario();

        $cancelled = $this->service->cancel($payout->id, 'admin-1', 'corr-cancel-1');

        $this->assertEquals(CommissionPayoutStatus::Cancelled, $cancelled->status);
        $this->assertEquals(
            2,
            CommissionModel::where('status', CommissionStatus::Pending->value)->count()
        );
    }

    public function test_cancel_completed_throws_payout_cancellation_not_allowed(): void
    {
        $agent  = AgentModel::factory()->create();
        $payout = CommissionPayoutModel::factory()->create([
            'agent_id' => $agent->id,
            'status'   => CommissionPayoutStatus::Completed->value,
        ]);

        $this->expectException(PayoutCancellationNotAllowedException::class);

        $this->service->cancel($payout->id, 'admin-1', 'corr-cancel-2');
    }

    public function test_cancel_with_partially_reversed_commissions_only_reverts_non_reversed(): void
    {
        $agent  = AgentModel::factory()->create(['status' => AgentStatus::Suspended->value]);
        $payout = CommissionPayoutModel::factory()->create([
            'agent_id' => $agent->id,
            'status'   => CommissionPayoutStatus::Deferred->value,
        ]);
        CommissionModel::factory()->create([
            'agent_id'  => $agent->id,
            'payout_id' => $payout->id,
            'status'    => CommissionStatus::Approved->value,
        ]);
        CommissionModel::factory()->create([
            'agent_id'  => $agent->id,
            'payout_id' => $payout->id,
            'status'    => CommissionStatus::Reversed->value,
        ]);

        $this->service->cancel($payout->id, 'admin-1', 'corr-cancel-3');

        $this->assertEquals(1, CommissionModel::where('status', CommissionStatus::Pending->value)->count());
        $this->assertEquals(1, CommissionModel::where('status', CommissionStatus::Reversed->value)->count());
    }

    public function test_listener_replay_idempotency_executes_deferred_once(): void
    {
        [, $payout] = $this->makeSuspendedScenario();
        AgentFloatWalletModel::factory()->create([
            'agent_id'                 => $payout->agent_id,
            'ledger_account_reference' => "agent_float:{$payout->agent_id}",
        ]);

        $this->ledger->method('postEntry')->willReturn('journal-idempotent-1');

        $processedRepo = $this->createMock(ProcessedEventRepositoryInterface::class);
        $processedRepo->expects($this->exactly(2))->method('hasBeenProcessed')
            ->willReturnOnConsecutiveCalls(false, true);
        $processedRepo->expects($this->once())->method('markAsProcessed');

        $listener = new AgentReactivatedCommissionListener($this->service, $processedRepo);

        $event = new AgentReactivatedEvent(
            agentId:       $payout->agent_id,
            reactivatedBy: 'admin-1',
            occurredAt:    now()->toIso8601String(),
            correlationId: 'corr-idempotent-1',
        );

        $listener->handle($event);
        $listener->handle($event);

        $payout->refresh();
        $this->assertEquals(CommissionPayoutStatus::Completed, $payout->status);
    }
}
