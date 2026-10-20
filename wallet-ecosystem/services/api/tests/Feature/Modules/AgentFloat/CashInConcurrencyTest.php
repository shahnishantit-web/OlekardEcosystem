<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AgentFloat;

use Tests\TestCase;

class CashInConcurrencyTest extends TestCase
{
    public function test_concurrent_cash_ins_do_not_overdraft_float(): void
    {
        // 10 concurrent goroutine-style cash-ins each for BWP 100 against BWP 500 float
        // Exactly 5 should succeed, 5 should fail with INSUFFICIENT_FLOAT; total debited = 500
        $this->markTestIncomplete('Requires parallel HTTP simulation or queue-based concurrency.');
    }

    public function test_concurrent_idempotent_replays_produce_single_journal(): void
    {
        // Same idempotency key fired 5× concurrently → exactly one journal entry
        $this->markTestIncomplete('Requires concurrent request simulation with shared idempotency key.');
    }
}
