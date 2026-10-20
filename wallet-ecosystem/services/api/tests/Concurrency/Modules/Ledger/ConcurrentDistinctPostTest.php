<?php

declare(strict_types=1);

namespace Tests\Concurrency\Modules\Ledger;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

/**
 * CC-E07-001 / T081 — 10 posts with distinct Idempotency-Keys to same pair of accounts.
 *
 * All 10 must commit; getBalance() must equal the aggregate sum; ledger:check-balance exits 0.
 * Serialised here to test the sequential posting path. The SELECT FOR UPDATE inside
 * LedgerPostingService ensures row-level locking during each commit.
 */
final class ConcurrentDistinctPostTest extends LedgerTestCase
{
    // CC-E07-001: 10 distinct-key posts to same accounts → all 10 commit, balance correct.
    public function test_ten_distinct_key_posts_all_commit_and_balance_is_accurate(): void
    {
        $admin    = $this->makeAdminWithPermission('ledger.post');
        $debitRef  = 'dist-dr-' . substr((string) Uuid::uuid7(), 0, 8);
        $creditRef = 'dist-cr-' . substr((string) Uuid::uuid7(), 0, 8);
        $amount    = 500;
        $count     = 10;

        for ($i = 0; $i < $count; $i++) {
            $this->withActorToken($admin)
                ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
                ->postJson('/v1/ledger/entries/debit', [
                    'currency_code'  => 'KES',
                    'correlation_id' => (string) Uuid::uuid7(),
                    'lines'          => [
                        ['ledger_account_reference' => $debitRef,  'direction' => 'debit',  'amount' => $amount],
                        ['ledger_account_reference' => $creditRef, 'direction' => 'credit', 'amount' => $amount],
                    ],
                ])
                ->assertStatus(201);
        }

        // All 10 journals committed
        $journalCount = DB::connection('platform')
            ->table('ledger.journals')
            ->where('status', 'posted')
            ->count();

        $this->assertSame($count, $journalCount, "Expected {$count} posted journals");

        // getBalance(creditRef) = 10 × 500 = 5000 (credit-normal)
        $expectedBalance = $count * $amount;
        $balance = app(LedgerModuleContract::class)->getBalance($creditRef);
        $this->assertSame($expectedBalance, $balance, "Balance must reflect all {$count} postings");

        // getBalance(debitRef) = -(10 × 500) for a liability account that was debited
        // (debit-normal: SUM debits - SUM credits = 5000 - 0 = 5000)
        $debitBalance = app(LedgerModuleContract::class)->getBalance($debitRef);
        $this->assertSame($expectedBalance, $debitBalance);

        // ledger:check-balance exits 0
        $this->artisan('ledger:check-balance')->assertSuccessful();
    }
}
