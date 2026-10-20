<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AgentFloat;

use Tests\TestCase;

class CashInTest extends TestCase
{
    public function test_agent_can_perform_cash_in_for_linked_customer(): void
    {
        // BWP 200 cash-in: float decreases, customer balance increases; 201 response
        $this->markTestIncomplete('Requires agent with float wallet + linked customer with wallet.');
    }

    public function test_insufficient_float_returns_422(): void
    {
        // Cash-in exceeds float balance → INSUFFICIENT_FLOAT
        $this->markTestIncomplete('Seed agent with BWP 1 float, attempt BWP 200 cash-in.');
    }

    public function test_suspended_agent_float_returns_422(): void
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
        // Customer not in agent_customer_relationships → 403
        $this->markTestIncomplete('Attempt cash-in for unlinked customer.');
    }

    public function test_idempotent_cash_in_replay_returns_200(): void
    {
        // Same Idempotency-Key → 200; balance unchanged; no second journal
        $this->markTestIncomplete('Replay same idempotency key.');
    }
}
