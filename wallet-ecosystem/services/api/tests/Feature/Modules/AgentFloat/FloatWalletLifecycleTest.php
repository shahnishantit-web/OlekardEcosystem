<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AgentFloat;

use Tests\TestCase;

class FloatWalletLifecycleTest extends TestCase
{
    public function test_float_wallet_is_suspended_when_agent_is_suspended(): void
    {
        // Dispatch AgentSuspendedEvent → wallet.wallets.status = 'suspended'
        // FloatWalletStatusChangedEvent emitted; subsequent cash-in returns 422
        $this->markTestIncomplete('Requires agent with active float wallet; dispatch AgentSuspendedEvent.');
    }

    public function test_float_wallet_is_reactivated_when_agent_is_reactivated(): void
    {
        // Dispatch AgentReactivatedEvent → wallet.wallets.status = 'active'
        // FloatWalletStatusChangedEvent emitted; subsequent cash-in succeeds
        $this->markTestIncomplete('Requires suspended agent with suspended float wallet; dispatch AgentReactivatedEvent.');
    }

    public function test_cash_in_rejected_when_float_wallet_is_suspended(): void
    {
        // Float wallet status=suspended → FLOAT_WALLET_NOT_ACTIVE (422)
        $this->markTestIncomplete('Set float wallet status=suspended directly; attempt cash-in.');
    }

    public function test_float_wallet_type_column_prevents_duplicate_for_same_type(): void
    {
        // Attempt to create second agent_float wallet for same agent → 409 FLOAT_WALLET_ALREADY_EXISTS
        $this->markTestIncomplete('Requires agent with existing float wallet; issue second create with different idempotency key.');
    }

    public function test_wallet_type_does_not_conflict_with_customer_wallet(): void
    {
        // Agent that also has a customer wallet can have agent_float wallet without unique index conflict
        $this->markTestIncomplete('Create both wallet_type=customer and wallet_type=agent_float for same owner_id.');
    }
}
