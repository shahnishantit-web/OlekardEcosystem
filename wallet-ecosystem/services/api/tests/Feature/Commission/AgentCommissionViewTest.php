<?php

declare(strict_types=1);

namespace Tests\Feature\Commission;

use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\Commission\Domain\Enums\CommissionStatus;
use App\Modules\Commission\Infrastructure\Models\CommissionModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class AgentCommissionViewTest extends TestCase
{
    use DatabaseTransactions;

    public function test_pending_list_returns_correct_pending_total(): void
    {
        $admin = $this->createAuthenticatedAdmin(['commission.view_any']);
        $agent = AgentModel::factory()->create();

        CommissionModel::factory()->count(3)->create([
            'agent_id' => $agent->id,
            'amount'   => 200,
            'status'   => CommissionStatus::Pending->value,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/v1/agents/{$agent->id}/commissions/pending");

        $response->assertStatus(200)
            ->assertJsonPath('pending_total', 600)
            ->assertJsonCount(3, 'data');
    }

    public function test_paid_list_with_date_filter(): void
    {
        $admin = $this->createAuthenticatedAdmin(['commission.view_any']);
        $agent = AgentModel::factory()->create();

        CommissionModel::factory()->count(2)->create([
            'agent_id' => $agent->id,
            'status'   => CommissionStatus::Paid->value,
            'updated_at' => now()->subDays(5),
        ]);
        CommissionModel::factory()->create([
            'agent_id' => $agent->id,
            'status'   => CommissionStatus::Paid->value,
            'updated_at' => now()->subDays(30),
        ]);

        $from = now()->subDays(10)->toDateString();
        $response = $this->actingAs($admin)
            ->getJson("/v1/agents/{$agent->id}/commissions?from={$from}");

        $response->assertStatus(200)->assertJsonCount(2, 'data');
    }

    public function test_paid_commission_disappears_from_pending_after_payout(): void
    {
        $admin = $this->createAuthenticatedAdmin(['commission.view_any']);
        $agent = AgentModel::factory()->create();

        $commission = CommissionModel::factory()->create([
            'agent_id' => $agent->id,
            'status'   => CommissionStatus::Pending->value,
        ]);

        $this->actingAs($admin)
            ->getJson("/v1/agents/{$agent->id}/commissions/pending")
            ->assertJsonCount(1, 'data');

        $commission->update(['status' => CommissionStatus::Paid->value]);

        $this->actingAs($admin)
            ->getJson("/v1/agents/{$agent->id}/commissions/pending")
            ->assertJsonPath('pending_total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_cross_agent_non_admin_returns_403(): void
    {
        $agent1  = AgentModel::factory()->create();
        $agent2  = AgentModel::factory()->create();
        $user    = $this->createAuthenticatedUser(['commission.view_own']);

        $this->actingAs($user)
            ->getJson("/v1/agents/{$agent1->id}/commissions")
            ->assertStatus(403);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $agent = AgentModel::factory()->create();
        $this->getJson("/v1/agents/{$agent->id}/commissions")->assertStatus(401);
    }
}
