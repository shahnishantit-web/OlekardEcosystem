<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AgentFloat;

use Tests\TestCase;

class CashOutConcurrencyTest extends TestCase
{
    public function test_concurrent_cash_outs_do_not_exceed_customer_balance(): void
    {
        // 10 concurrent cash-outs each BWP 100 against customer BWP 500 → exactly 5 succeed
        $this->markTestIncomplete('Requires parallel HTTP simulation or queue-based concurrency.');
    }

    public function test_concurrent_cash_outs_with_different_customers_are_independent(): void
    {
        // Cash-outs for different customers proceed in parallel without contention
        $this->markTestIncomplete('Requires multiple customer fixtures with independent wallets.');
    }
}
