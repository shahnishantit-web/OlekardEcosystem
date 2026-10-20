<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AgentFloat;

use Tests\TestCase;

class FloatBalanceViewTest extends TestCase
{
    public function test_funded_wallet_shows_correct_balance(): void
    {
        // After BWP 5000 funding: balance=500000, status=active
        $this->markTestIncomplete('Requires seeded float wallet with ledger balance.');
    }

    public function test_depleted_wallet_shows_low_float_alert_true(): void
    {
        // Float below threshold: low_float_alert=true in response
        $this->markTestIncomplete('Requires float_limits with low_float_threshold set and balance below it.');
    }

    public function test_missing_wallet_returns_404(): void
    {
        // Non-existent agentId → 404 FLOAT_WALLET_NOT_FOUND
        $this->markTestIncomplete('Use non-existent UUID as agentId.');
    }

    public function test_unauthorized_access_returns_403(): void
    {
        // Agent viewing another agent's wallet → 403
        $this->markTestIncomplete('Wire up agent token trying to view different agent wallet.');
    }
}
