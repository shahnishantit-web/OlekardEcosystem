<?php

declare(strict_types=1);

namespace Tests\Feature\AdminApp;

use Tests\TestCase;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use Illuminate\Support\Facades\DB;

final class AgentTest extends TestCase
{
    public function test_list_pending_agents(): void
    {
        $response = $this->actingAsAdminWithPermission('adminapp.agents.manage')
            ->getJson('/v1/admin-app/agents?status=pending_approval');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_approve_pending_agent_returns_202(): void
    {
        $agentId = $this->createPendingAgent();

        $response = $this->actingAsAdminWithPermission('adminapp.agents.approve')
            ->withHeaders(['Idempotency-Key' => 'approve-agent-' . $agentId])
            ->postJson("/v1/admin-app/agents/{$agentId}/approve");

        $response->assertStatus(202)
            ->assertJsonPath('data.action_type', 'adminapp.agent.approve')
            ->assertJsonPath('data.status', 'pending_approval');
    }

    public function test_float_over_limit_returns_422(): void
    {
        $agentId = $this->createActiveAgent(floatBalance: 4900000, floatLimit: 5000000);
        $ledger = \Mockery::mock(LedgerModuleContract::class);
        $ledger->shouldReceive('getBalance')->once()->andReturn(4900000);
        $this->app->instance(LedgerModuleContract::class, $ledger);

        $response = $this->actingAsAdminWithPermission('adminapp.float.manage')
            ->withHeaders(['Idempotency-Key' => 'float-overlimit-' . $agentId])
            ->postJson("/v1/admin-app/float/{$agentId}/adjust", [
                'direction'          => 'credit',
                'amount_minor_units' => 200000,
                'reason'             => 'Top-up',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'ADMINAPP_FLOAT_001');
    }

    public function test_operations_role_denied_float_endpoints(): void
    {
        $response = $this->actingAsAdminWithPermission('adminapp.agents.manage')
            ->getJson('/v1/admin-app/float');

        $response->assertStatus(403);
    }

    public function test_suspend_agent_creates_audit_entry(): void
    {
        $agentId = $this->createActiveAgent();

        $response = $this->actingAsAdminWithPermission('adminapp.agents.manage')
            ->withHeaders(['Idempotency-Key' => 'suspend-agent-' . $agentId])
            ->patchJson("/v1/admin-app/agents/{$agentId}/status", [
                'action' => 'suspend',
                'reason' => 'Policy violation',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.agent_status', 'suspended');
    }

    public function test_agent_status_can_cycle_using_distinct_idempotency_keys(): void
    {
        $agentId = $this->createActiveAgent();
        $admin = fn () => $this->actingAsAdminWithPermission('adminapp.agents.manage');

        $admin()->withHeaders(['Idempotency-Key' => 'suspend-1-'.$agentId])
            ->patchJson("/v1/admin-app/agents/{$agentId}/status", ['action' => 'suspend', 'reason' => 'First verification'])
            ->assertStatus(200)->assertJsonPath('data.agent_status', 'suspended');
        $this->assertSame('suspended', DB::connection('platform')->table('agent.agents')->where('id', $agentId)->value('status'));

        $admin()->withHeaders(['Idempotency-Key' => 'reactivate-1-'.$agentId])
            ->patchJson("/v1/admin-app/agents/{$agentId}/status", ['action' => 'reactivate', 'reason' => 'Restore verification'])
            ->assertStatus(200)->assertJsonPath('data.agent_status', 'active');
        $this->assertSame('active', DB::connection('platform')->table('agent.agents')->where('id', $agentId)->value('status'));

        $admin()->withHeaders(['Idempotency-Key' => 'suspend-2-'.$agentId])
            ->patchJson("/v1/admin-app/agents/{$agentId}/status", ['action' => 'suspend', 'reason' => 'Second verification'])
            ->assertStatus(200)->assertJsonPath('data.agent_status', 'suspended');
        $this->assertSame('suspended', DB::connection('platform')->table('agent.agents')->where('id', $agentId)->value('status'));
    }

    private function createPendingAgent(): string
    {
        $userId = $this->createAgentUser();
        $applicationId = \Illuminate\Support\Str::uuid()->toString();
        DB::connection('platform')->table('agent.agent_applications')->insert([
            'id' => $applicationId,
            'user_id' => $userId,
            'agent_type' => 'individual',
            'status' => 'under_review',
            'correlation_id' => \Illuminate\Support\Str::uuid()->toString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $applicationId;
    }

    private function createActiveAgent(int $floatBalance = 0, int $floatLimit = 5000000): string
    {
        $userId = $this->createAgentUser();
        $applicationId = \Illuminate\Support\Str::uuid()->toString();
        $agentId = \Illuminate\Support\Str::uuid()->toString();
        $now = now();
        DB::connection('platform')->table('agent.agent_applications')->insert([
            'id' => $applicationId,
            'user_id' => $userId,
            'agent_type' => 'individual',
            'status' => 'approved',
            'correlation_id' => \Illuminate\Support\Str::uuid()->toString(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('platform')->table('agent.agents')->insert([
            'id' => $agentId,
            'application_id' => $applicationId,
            'user_id' => $userId,
            'agent_type' => 'individual',
            'status' => 'active',
            'approved_by' => \Illuminate\Support\Str::uuid()->toString(),
            'approved_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $floatWalletId = \Illuminate\Support\Str::uuid()->toString();
        DB::connection('platform')->table('agent.float_wallets')->insert([
            'id' => $floatWalletId,
            'agent_id' => $agentId,
            'wallet_id' => \Illuminate\Support\Str::uuid()->toString(),
            'ledger_account_reference' => 'test-float:'.$agentId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('platform')->table('agent.float_limits')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'agent_id' => $agentId,
            'currency_code' => 'BWP',
            'max_balance' => $floatLimit,
            'max_balance_is_tbd' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $agentId;
    }

    private function createAgentUser(): string
    {
        $userId = \Illuminate\Support\Str::uuid()->toString();
        DB::connection('platform')->table('identity.users')->insert([
            'id' => $userId,
            'email' => 'agent-'.$userId.'@test.local',
            'mobile_number' => '+2677'.random_int(1000000, 9999999),
            'status' => 'active',
            'actor_type' => 'AGENT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $userId;
    }
}
