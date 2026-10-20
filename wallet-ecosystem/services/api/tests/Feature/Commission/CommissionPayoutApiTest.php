<?php

declare(strict_types=1);

namespace Tests\Feature\Commission;

use App\Modules\Agent\Domain\Enums\AgentStatus;
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

final class CommissionPayoutApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->instance(AuditServiceInterface::class, $this->createStub(AuditServiceInterface::class));
        $this->instance(EventPublisherInterface::class, $this->createStub(EventPublisherInterface::class));

        $ledger = $this->createMock(LedgerModuleContract::class);
        $ledger->method('postEntry')->willReturn('mock-journal');
        $this->instance(LedgerModuleContract::class, $ledger);
    }

    private function makeScenario(): array
    {
        $agent = AgentModel::factory()->create(['status' => AgentStatus::Active->value]);
        AgentFloatWalletModel::factory()->create([
            'agent_id' => $agent->id,
            'ledger_account_reference' => "agent_float:{$agent->id}",
        ]);
        $commissions = CommissionModel::factory()->count(2)->create([
            'agent_id'      => $agent->id,
            'amount'        => 200,
            'status'        => CommissionStatus::Pending->value,
            'currency_code' => 'BWP',
        ]);
        return [$agent, $commissions];
    }

    public function test_post_creates_completed_payout_201(): void
    {
        $admin = $this->createAuthenticatedAdmin(['commission.payout.approve']);
        [$agent, $commissions] = $this->makeScenario();

        $response = $this->actingAs($admin)->postJson('/v1/commission-payouts', [
            'agent_id'       => $agent->id,
            'commission_ids' => $commissions->pluck('id')->all(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.total_amount', 400);
    }

    public function test_get_payout_returns_200_with_details(): void
    {
        $admin = $this->createAuthenticatedAdmin(['commission.payout.approve']);
        [$agent, $commissions] = $this->makeScenario();

        $payout = $this->actingAs($admin)->postJson('/v1/commission-payouts', [
            'agent_id'       => $agent->id,
            'commission_ids' => $commissions->pluck('id')->all(),
        ])->json('data.id');

        $this->actingAs($admin)
            ->getJson("/v1/commission-payouts/{$payout}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $payout);
    }

    public function test_non_admin_gets_403(): void
    {
        $user = $this->createAuthenticatedUser([]);
        [$agent] = $this->makeScenario();

        $this->actingAs($user)->postJson('/v1/commission-payouts', [
            'agent_id'       => $agent->id,
            'commission_ids' => [],
        ])->assertStatus(403);
    }

    public function test_already_processed_commission_returns_400(): void
    {
        $admin = $this->createAuthenticatedAdmin(['commission.payout.approve']);
        $agent = AgentModel::factory()->create();
        $paid  = CommissionModel::factory()->create([
            'agent_id' => $agent->id,
            'status'   => CommissionStatus::Paid->value,
        ]);

        $this->actingAs($admin)->postJson('/v1/commission-payouts', [
            'agent_id'       => $agent->id,
            'commission_ids' => [$paid->id],
        ])->assertStatus(400);
    }
}
