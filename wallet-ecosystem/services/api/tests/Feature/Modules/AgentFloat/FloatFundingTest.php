<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AgentFloat;

use Tests\TestCase;

class FloatFundingTest extends TestCase
{
    public function test_admin_can_fund_float_wallet(): void
    {
        // POST fund BWP 5000 → 201 with type=funding, direction=debit
        $this->markTestIncomplete('Requires seeded agent float wallet and admin token.');
    }

    public function test_idempotent_fund_replay_returns_200_with_no_double_credit(): void
    {
        // Same key replayed → 200; balance unchanged; ledger has single journal
        $this->markTestIncomplete('Requires funded float wallet and idempotency key replay.');
    }

    public function test_invalid_amount_returns_422(): void
    {
        // amount=0 or negative → VALIDATION_ERROR
        $this->markTestIncomplete('Basic validation test.');
    }

    public function test_unknown_agent_returns_404(): void
    {
        // Non-existent agentId → FLOAT_WALLET_NOT_FOUND
        $this->markTestIncomplete('Use non-existent UUID as agentId.');
    }

    public function test_missing_funding_account_config_returns_503(): void
    {
        // ledger.agent_float_funding_account.BWP not configured → FLOAT_FUNDING_ACCOUNT_NOT_CONFIGURED
        $this->markTestIncomplete('Requires config to be absent in test environment.');
    }

    public function test_unauthorized_returns_403(): void
    {
        // Agent token without fund permission → 403
        $this->markTestIncomplete('Wire up agent token without agent_float.wallet.fund permission.');
    }
}
