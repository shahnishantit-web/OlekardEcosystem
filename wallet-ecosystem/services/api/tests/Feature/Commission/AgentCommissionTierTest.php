<?php

declare(strict_types=1);

namespace Tests\Feature\Commission;

use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class AgentCommissionTierTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->instance(AuditServiceInterface::class, $this->createStub(AuditServiceInterface::class));
    }

    public function test_patch_sets_tier_returns_200(): void
    {
        $admin = $this->createAuthenticatedAdmin(['commission.tier.manage']);
        $agent = AgentModel::factory()->create(['agent_tier' => null]);

        $this->actingAs($admin)
            ->patchJson("/v1/agents/{$agent->id}/commission-tier", ['agent_tier' => 'premium'])
            ->assertStatus(200)
            ->assertJsonPath('data.agent_tier', 'premium');

        $agent->refresh();
        $this->assertEquals('premium', $agent->agent_tier);
    }

    public function test_null_tier_reverts_to_global_rule(): void
    {
        $admin = $this->createAuthenticatedAdmin(['commission.tier.manage']);
        $agent = AgentModel::factory()->create(['agent_tier' => 'premium']);

        $this->actingAs($admin)
            ->patchJson("/v1/agents/{$agent->id}/commission-tier", ['agent_tier' => null])
            ->assertStatus(200)
            ->assertJsonPath('data.agent_tier', null);

        $agent->refresh();
        $this->assertNull($agent->agent_tier);
    }

    public function test_non_admin_patch_returns_403(): void
    {
        $user  = $this->createAuthenticatedUser([]);
        $agent = AgentModel::factory()->create();

        $this->actingAs($user)
            ->patchJson("/v1/agents/{$agent->id}/commission-tier", ['agent_tier' => 'premium'])
            ->assertStatus(403);
    }
}
