<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Wallet;

use PHPUnit\Framework\TestCase;

/**
 * Pure formula tests — no database, no services.
 * Verifies: available_balance = max(0, ledger_balance - held_amount).
 */
final class BalanceFormulaTest extends TestCase
{
    /** @test */
    public function available_balance_equals_ledger_minus_held(): void
    {
        $ledger = 300;
        $held   = 80;
        $this->assertSame(220, max(0, $ledger - $held));
    }

    /** @test */
    public function available_balance_is_zero_when_held_equals_ledger(): void
    {
        $this->assertSame(0, max(0, 80 - 80));
    }

    /** @test */
    public function available_balance_is_zero_when_held_exceeds_ledger(): void
    {
        // Defensive case: max(0,...) prevents negative
        $this->assertSame(0, max(0, 80 - 200));
    }

    /** @test */
    public function available_balance_for_new_pocket_is_zero(): void
    {
        $this->assertSame(0, max(0, 0 - 0));
    }

    /** @test */
    public function available_balance_is_never_negative(): void
    {
        $cases = [
            [0, 0],
            [100, 0],
            [100, 100],
            [100, 150],
            [PHP_INT_MAX, 0],
        ];

        foreach ($cases as [$ledger, $held]) {
            $available = max(0, $ledger - $held);
            $this->assertGreaterThanOrEqual(0, $available);
        }
    }
}
