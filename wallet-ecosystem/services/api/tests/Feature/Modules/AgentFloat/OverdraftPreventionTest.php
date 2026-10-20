<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AgentFloat;

use Tests\TestCase;

class OverdraftPreventionTest extends TestCase
{
    public function test_float_balance_never_goes_negative_under_concurrent_load(): void
    {
        // SC-E13-003: spawn 20 concurrent cash-in requests summing to 2× float balance
        // Assert final balance >= 0 and total posted = initial balance
        $this->markTestIncomplete('Requires concurrent simulation; verify no negative balance via LedgerModuleContract::getBalance().');
    }

    public function test_select_for_update_prevents_race_on_float_wallet(): void
    {
        // Verify that float_wallets row is locked (SELECT FOR UPDATE) before balance check
        $this->markTestIncomplete('Instrument DB queries to confirm lockForUpdate() is called.');
    }
}
