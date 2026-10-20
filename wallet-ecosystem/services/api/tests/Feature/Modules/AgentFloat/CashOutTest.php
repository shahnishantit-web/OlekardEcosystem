<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AgentFloat;

use Tests\TestCase;

class CashOutTest extends TestCase
{
    public function test_agent_can_perform_cash_out_for_linked_customer(): void
    {
        // BWP 100 cash-out: customer balance decreases, float increases; 201 response
        $this->markTestIncomplete('Requires agent with float + linked customer with sufficient balance.');
    }

    public function test_insufficient_customer_balance_returns_422(): void
    {
        // Customer balance < amount → INSUFFICIENT_CUSTOMER_BALANCE
        $this->markTestIncomplete('Seed customer with BWP 0 balance, attempt BWP 100 cash-out.');
    }

    public function test_suspended_agent_returns_422(): void
    {
        // Agent suspended → AGENT_SUSPENDED
        $this->markTestIncomplete('Requires suspended agent.');
    }

    public function test_frozen_customer_wallet_returns_422(): void
    {
        // Customer wallet frozen → CUSTOMER_WALLET_FROZEN
        $this->markTestIncomplete('Requires customer with frozen wallet.');
    }

    public function test_unlinked_customer_returns_403(): void
    {
        // Customer not linked to agent → 403
        $this->markTestIncomplete('Attempt cash-out for unlinked customer.');
    }

    public function test_idempotent_cash_out_replay_returns_200(): void
    {
        // Same Idempotency-Key → 200; balances unchanged; single journal
        $this->markTestIncomplete('Replay same idempotency key.');
    }

    public function test_cash_out_recovers_low_float_alert(): void
    {
        // Agent was in low-float alert; cash-out pushes float above threshold → alerted=false
        $this->markTestIncomplete('Requires float_limits.low_float_threshold set and low_float_alerted=true.');
    }
}
