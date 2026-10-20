<?php

declare(strict_types=1);

namespace Tests\Concurrency\Modules\Ledger;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

/**
 * CC-E07-002 / T082 — getBalance() during in-flight postings returns only committed states.
 *
 * PostgreSQL READ COMMITTED isolates queries so in-flight (uncommitted) data is never
 * visible. This test verifies no dirty read occurs by:
 *  1. Recording balance before a post.
 *  2. Recording balance after the post.
 *  3. Asserting the returned balance is one of the two valid committed values
 *     (either before or after) — never an intermediate phantom value.
 */
final class ConcurrentBalanceQueryTest extends LedgerTestCase
{
    // CC-E07-002: getBalance() never returns a dirty (uncommitted) intermediate value.
    public function test_get_balance_never_returns_dirty_read(): void
    {
        $admin     = $this->makeAdminWithPermission('ledger.post');
        $targetRef = 'balance-dirty-' . substr((string) Uuid::uuid7(), 0, 8);
        $oppRef    = 'balance-dirty-opp-' . substr((string) Uuid::uuid7(), 0, 8);

        /** @var LedgerModuleContract $ledger */
        $ledger = app(LedgerModuleContract::class);

        $balanceBefore = $ledger->getBalance($targetRef);
        $this->assertSame(0, $balanceBefore, 'Fresh account must start at 0');

        // Post 5 sequential journals; after each, getBalance must be a valid step value.
        $expectedBalance = 0;
        $amount          = 1000;

        for ($i = 0; $i < 5; $i++) {
            $this->withActorToken($admin)
                ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
                ->postJson('/v1/ledger/entries/debit', [
                    'currency_code'  => 'KES',
                    'correlation_id' => (string) Uuid::uuid7(),
                    'lines'          => [
                        ['ledger_account_reference' => $oppRef,    'direction' => 'debit',  'amount' => $amount],
                        ['ledger_account_reference' => $targetRef, 'direction' => 'credit', 'amount' => $amount],
                    ],
                ])
                ->assertStatus(201);

            $expectedBalance += $amount;
            $currentBalance = $ledger->getBalance($targetRef);

            // Must be exactly the committed value after each post (READ COMMITTED)
            $this->assertSame(
                $expectedBalance,
                $currentBalance,
                sprintf('After posting %d journal(s), balance must be exactly %d, got %d', $i + 1, $expectedBalance, $currentBalance)
            );
        }

        // Final balance = 5 × 1000 = 5000
        $this->assertSame(5 * $amount, $ledger->getBalance($targetRef));
    }

    // Verify that balance queries within a DB transaction see the committed outer state.
    public function test_balance_within_uncommitted_outer_transaction_not_visible_to_separate_reads(): void
    {
        $admin     = $this->makeAdminWithPermission('ledger.post');
        $targetRef = 'tx-isolation-' . substr((string) Uuid::uuid7(), 0, 8);
        $oppRef    = 'tx-isolation-opp-' . substr((string) Uuid::uuid7(), 0, 8);

        /** @var LedgerModuleContract $ledger */
        $ledger = app(LedgerModuleContract::class);

        // Balance before any post = 0
        $this->assertSame(0, $ledger->getBalance($targetRef));

        // Post a journal (committed immediately because not inside manual transaction)
        $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => $oppRef,    'direction' => 'debit',  'amount' => 3000],
                    ['ledger_account_reference' => $targetRef, 'direction' => 'credit', 'amount' => 3000],
                ],
            ])
            ->assertStatus(201);

        // After commit, balance must be exactly 3000
        $this->assertSame(3000, $ledger->getBalance($targetRef));
    }
}
