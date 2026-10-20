<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AgentFloat;

use App\Modules\AgentFloat\Infrastructure\Models\AgentFloatWalletModel;
use App\Modules\AgentFloat\Infrastructure\Models\FloatLimitModel;
use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\Wallet\Infrastructure\Models\WalletModel;
use Tests\TestCase;

class FloatWalletProvisioningTest extends TestCase
{
    public function test_admin_can_create_float_wallet_for_approved_agent(): void
    {
        // Happy path: 201 with balance=0 and status=active
        $this->markTestIncomplete('Requires seeded agent and admin token — implement with TestCase helpers.');
    }

    public function test_idempotent_replay_returns_200(): void
    {
        // Same Idempotency-Key second call returns 200 with same wallet (no duplicate created)
        $this->markTestIncomplete('Requires seeded agent and admin token.');
    }

    public function test_duplicate_wallet_returns_409(): void
    {
        // Different Idempotency-Key, same agent → FLOAT_WALLET_ALREADY_EXISTS
        $this->markTestIncomplete('Requires seeded agent with existing float wallet.');
    }

    public function test_ineligible_agent_returns_422(): void
    {
        // Agent with status=suspended → AGENT_NOT_ELIGIBLE
        $this->markTestIncomplete('Requires seeded suspended agent.');
    }

    public function test_missing_idempotency_key_returns_422(): void
    {
        // POST without Idempotency-Key header → VALIDATION_ERROR
        $this->markTestIncomplete('Requires auth token.');
    }

    public function test_unauthorized_returns_401(): void
    {
        // No auth token → 401
        $this->markTestIncomplete('Wire up unauthenticated request.');
    }

    public function test_insufficient_permission_returns_403(): void
    {
        // Auth token without agent_float.wallet.create → 403
        $this->markTestIncomplete('Wire up agent-only token without create permission.');
    }
}
