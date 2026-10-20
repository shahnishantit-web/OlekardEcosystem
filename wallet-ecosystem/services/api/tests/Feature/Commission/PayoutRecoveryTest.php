<?php

declare(strict_types=1);

namespace Tests\Feature\Commission;

use App\Modules\Agent\Domain\Enums\AgentStatus;
use App\Modules\Agent\Domain\Events\AgentReactivatedEvent;
use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\AgentFloat\Infrastructure\Models\AgentFloatWalletModel;
use App\Modules\Commission\Domain\Enums\CommissionPayoutStatus;
use App\Modules\Commission\Domain\Enums\CommissionStatus;
use App\Modules\Commission\Infrastructure\Models\CommissionModel;
use App\Modules\Commission\Infrastructure\Models\CommissionPayoutModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class PayoutRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->instance(AuditServiceInterface::class, $this->createStub(AuditServiceInterface::class));
        $this->instance(EventPublisherInterface::class, $this->createStub(EventPublisherInterface::class));

        $ledger = $this->createMock(LedgerModuleContract::class);
        $ledger->method('postEntry')->willReturn('mock-journal-recovery');
        $this->instance(LedgerModuleContract::class, $ledger);
    }

    public function test_approve_suspended_agent_returns_201_deferred(): void
    {
        $admin = $this->createAuthenticatedAdmin(['commission.payout.approve']);
        $agent = AgentModel::factory()->create(['status' => AgentStatus::Suspended->value]);
        $commissions = CommissionModel::factory()->count(2)->create([
            'agent_id'      => $agent->id,
            'amount'        => 200,
            'status'        => CommissionStatus::Pending->value,
            'currency_code' => 'BWP',
        ]);

        $this->actingAs($admin)->postJson('/v1/commission-payouts', [
            'agent_id'       => $agent->id,
            'commission_ids' => $commissions->pluck('id')->all(),
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'deferred');
    }

    public function test_cancel_deferred_payout_returns_200_cancelled(): void
    {
        $admin  = $this->createAuthenticatedAdmin(['commission.payout.cancel']);
        $agent  = AgentModel::factory()->create(['status' => AgentStatus::Suspended->value]);
        $payout = CommissionPayoutModel::factory()->create([
            'agent_id' => $agent->id,
            'status'   => CommissionPayoutStatus::Deferred->value,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/v1/commission-payouts/{$payout->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_cancel_completed_payout_returns_409(): void
    {
        $admin  = $this->createAuthenticatedAdmin(['commission.payout.cancel']);
        $agent  = AgentModel::factory()->create();
        $payout = CommissionPayoutModel::factory()->create([
            'agent_id' => $agent->id,
            'status'   => CommissionPayoutStatus::Completed->value,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/v1/commission-payouts/{$payout->id}")
            ->assertStatus(409);
    }

    public function test_agent_reactivation_event_executes_deferred_payouts(): void
    {
        $agent = AgentModel::factory()->create(['status' => AgentStatus::Active->value]);
        AgentFloatWalletModel::factory()->create([
            'agent_id'                 => $agent->id,
            'ledger_account_reference' => "agent_float:{$agent->id}",
        ]);

        $payout = CommissionPayoutModel::factory()->create([
            'agent_id'      => $agent->id,
            'status'        => CommissionPayoutStatus::Deferred->value,
            'total_amount'  => 400,
            'currency_code' => 'BWP',
        ]);
        CommissionModel::factory()->count(2)->create([
            'agent_id'  => $agent->id,
            'payout_id' => $payout->id,
            'status'    => CommissionStatus::Approved->value,
        ]);

        event(new AgentReactivatedEvent(
            agentId:       $agent->id,
            reactivatedBy: 'admin-1',
            occurredAt:    now()->toIso8601String(),
            correlationId: 'corr-reactivate-feat-1',
        ));

        $payout->refresh();
        $this->assertEquals(CommissionPayoutStatus::Completed, $payout->status);
    }
}
