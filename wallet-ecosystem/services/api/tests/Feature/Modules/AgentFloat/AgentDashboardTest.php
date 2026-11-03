<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AgentFloat;

use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\AgentFloat\Infrastructure\Models\AgentFloatWalletModel;
use App\Modules\AgentFloat\Infrastructure\Models\FloatLimitModel;
use App\Modules\AgentFloat\Infrastructure\Models\FloatTransactionModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class AgentDashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.redis.client' => 'predis']);
    }

    public function test_returns_only_the_authenticated_agents_dashboard_metrics(): void
    {
        $actor = $this->createAuthenticatedUser([
            'agent_float.wallet.view',
            'agent_float.transactions.view',
        ]);
        $agent = $this->createAgentForUser($actor->id);
        $floatWallet = AgentFloatWalletModel::factory()->create(['agent_id' => $agent->id]);
        FloatLimitModel::query()->create([
            'id' => (string) Uuid::uuid7(),
            'agent_id' => $agent->id,
            'currency_code' => 'BWP',
            'low_float_threshold' => 500000,
            'low_threshold_is_tbd' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([['cash_in', 1200], ['cash_out', 350], ['funding', 10000]] as [$type, $amount]) {
            FloatTransactionModel::query()->create([
                'id' => (string) Uuid::uuid7(),
                'agent_id' => $agent->id,
                'float_wallet_id' => $floatWallet->id,
                'type' => $type,
                'amount' => $amount,
                'currency_code' => 'BWP',
                'direction' => $type === 'cash_in' ? 'credit' : 'debit',
                'reference' => strtoupper($type).'-'.Uuid::uuid7(),
                'idempotency_key' => (string) Uuid::uuid7(),
                'status' => 'completed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        FloatTransactionModel::query()->create([
            'id' => (string) Uuid::uuid7(),
            'agent_id' => $agent->id,
            'float_wallet_id' => $floatWallet->id,
            'type' => 'cash_in',
            'amount' => 9900,
            'currency_code' => 'BWP',
            'direction' => 'credit',
            'reference' => 'FAILED-'.Uuid::uuid7(),
            'idempotency_key' => (string) Uuid::uuid7(),
            'status' => 'failed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ledger = $this->createMock(LedgerModuleContract::class);
        $ledger->method('getAccountHistory')->willReturn([
            'data' => [['posted_at' => now()->toIso8601String()]],
            'meta' => ['total' => 1, 'page' => 1, 'per_page' => 1, 'pages' => 1],
        ]);
        $ledger->method('getBalance')->willReturn(400000);
        $this->instance(LedgerModuleContract::class, $ledger);

        $this->actingAs($actor)
            ->getJson('/v1/agent/dashboard')
            ->assertOk()
            ->assertJsonPath('float_balance_minor_units', 400000)
            ->assertJsonPath('float_currency_code', 'BWP')
            ->assertJsonPath('low_float_threshold_minor_units', 500000)
            ->assertJsonPath('today_cash_in_count', 1)
            ->assertJsonPath('today_cash_in_amount_minor_units', 1200)
            ->assertJsonPath('today_cash_out_count', 1)
            ->assertJsonPath('today_cash_out_amount_minor_units', 350);
    }

    public function test_does_not_accept_another_agents_id_from_the_request(): void
    {
        $actor = $this->createAuthenticatedUser([
            'agent_float.wallet.view',
            'agent_float.transactions.view',
        ]);
        $otherActor = $this->createAuthenticatedUser([
            'agent_float.wallet.view',
            'agent_float.transactions.view',
        ]);
        $otherAgent = $this->createAgentForUser($otherActor->id);
        AgentFloatWalletModel::factory()->create(['agent_id' => $otherAgent->id]);

        $this->actingAs($actor)
            ->withHeader('X-Test-User-Id', $actor->id)
            ->getJson('/v1/agent/dashboard?agent_id='.$otherAgent->id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'AGENT_NOT_FOUND');
    }

    public function test_requires_both_existing_own_float_permissions(): void
    {
        $actor = $this->createAuthenticatedUser(['agent_float.wallet.view']);

        $this->actingAs($actor)
            ->getJson('/v1/agent/dashboard')
            ->assertForbidden();
    }

    public function test_unconfigured_threshold_fails_closed_instead_of_returning_a_default(): void
    {
        $actor = $this->createAuthenticatedUser([
            'agent_float.wallet.view',
            'agent_float.transactions.view',
        ]);
        $agent = $this->createAgentForUser($actor->id);
        AgentFloatWalletModel::factory()->create(['agent_id' => $agent->id]);
        FloatLimitModel::query()->create([
            'id' => (string) Uuid::uuid7(),
            'agent_id' => $agent->id,
            'currency_code' => 'BWP',
            'low_threshold_is_tbd' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($actor)
            ->getJson('/v1/agent/dashboard')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'FLOAT_THRESHOLD_NOT_CONFIGURED');
    }

    private function createAgentForUser(string $userId): AgentModel
    {
        $applicationId = (string) Uuid::uuid7();
        $agentId = (string) Uuid::uuid7();
        DB::connection('platform')->table('agent.agent_applications')->insert([
            'id' => $applicationId,
            'user_id' => $userId,
            'agent_type' => 'individual',
            'status' => 'approved',
            'correlation_id' => (string) Uuid::uuid7(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return AgentModel::query()->create([
            'id' => $agentId,
            'application_id' => $applicationId,
            'user_id' => $userId,
            'agent_type' => 'individual',
            'status' => 'active',
            'approved_by' => $userId,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
