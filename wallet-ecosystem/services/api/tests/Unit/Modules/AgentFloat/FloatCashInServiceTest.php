<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\AgentFloat;

use Tests\TestCase;

class FloatCashInServiceTest extends TestCase
{
    public function test_throws_insufficient_float_when_balance_equals_zero(): void
    {
        // Edge: zero balance → InsufficientFloatException
        $this->markTestIncomplete('Mock LedgerModuleContract returning balance=0.');
    }

    public function test_throws_float_limit_exceeded_when_amount_exceeds_per_transaction_limit(): void
    {
        // per_transaction_limit=50000 (BWP 500), amount=60000 (BWP 600) → FloatTransactionLimitExceededException
        $this->markTestIncomplete('Set up FloatLimitModel with per_transaction_limit set and is_tbd=false.');
    }

    public function test_skips_limit_check_when_per_transaction_limit_is_tbd(): void
    {
        // per_transaction_limit is_tbd=true → no limit enforced; proceeds to balance check
        $this->markTestIncomplete('FloatLimitModel with is_tbd=true for per_transaction_limit.');
    }

    public function test_throws_daily_limit_exceeded_when_daily_volume_exhausted(): void
    {
        // daily_volume_limit=200000 (BWP 2000); already processed BWP 1800 today → BWP 300 fails
        $this->markTestIncomplete('Requires float_transactions seeded for today summing to daily_volume_limit minus gap.');
    }

    public function test_posts_credit_float_debit_customer_journal(): void
    {
        // Verify journal lines: credit=floatRef, debit=customerPocketRef
        $this->markTestIncomplete('Mock LedgerModuleContract::postEntry() and assert call args.');
    }
}
