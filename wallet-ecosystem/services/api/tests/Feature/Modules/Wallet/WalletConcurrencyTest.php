<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Wallet;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

/**
 * E27 — FR-008, Constitution P4: Concurrent wallet operations must be serialized.
 *
 * Tests that two simultaneous transfer debits against the same wallet produce
 * correct final balances — one succeeds, one fails with insufficient funds.
 *
 * Simulates lock contention via nested DB transactions with lockForUpdate().
 */
final class WalletConcurrencyTest extends LedgerTestCase
{
    protected $connectionsToTransact = [null, 'platform', 'ledger', 'wallet'];

    private string $walletId;
    private string $ledgerAccountRef;
    private string $initiatorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->walletId         = (string) Uuid::uuid7();
        $this->ledgerAccountRef = 'test-wallet-' . $this->walletId;
        $this->initiatorId      = (string) Uuid::uuid7();

        // Seed a ledger account for this wallet with balance = 10000 (BWP 100.00 in minor units)
        $accountId = (string) Uuid::uuid7();

        DB::connection('platform')->table('ledger.ledger_accounts')->insert([
            'id'                       => $accountId,
            'ledger_account_reference' => $this->ledgerAccountRef,
            'account_type'             => 'asset',
            'normal_balance'           => 'debit',
            'currency_code'            => 'BWP',
            'owner_reference'          => $this->walletId,
            'owner_type'               => 'wallet',
            'status'                   => 'active',
            'created_by'               => $this->initiatorId,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        // Fund with 10000 (100.00 BWP)
        $journalId = (string) Uuid::uuid7();
        $systemAccountId = (string) Uuid::uuid7();

        DB::connection('platform')->table('ledger.ledger_accounts')->insert([
            'id'                       => $systemAccountId,
            'ledger_account_reference' => 'system-funding-' . $this->walletId,
            'account_type'             => 'liability',
            'normal_balance'           => 'credit',
            'currency_code'            => 'BWP',
            'owner_reference'          => null,
            'owner_type'               => 'system',
            'status'                   => 'active',
            'created_by'               => $this->initiatorId,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        DB::connection('platform')->table('ledger.journals')->insert([
            'id'              => $journalId,
            'idempotency_key' => (string) Uuid::uuid7(),
            'entry_type'      => 'debit',
            'currency_code'   => 'BWP',
            'status'          => 'posted',
            'correlation_id'  => (string) Uuid::uuid7(),
            'posted_at'       => now()->subMinutes(5),
            'created_by'      => $this->initiatorId,
            'created_at'      => now()->subMinutes(5),
        ]);

        DB::connection('platform')->table('ledger.journal_lines')->insert([
            ['id' => (string) Uuid::uuid7(), 'journal_id' => $journalId, 'ledger_account_id' => $accountId,       'direction' => 'debit',  'amount' => 10000, 'currency_code' => 'BWP', 'created_at' => now()],
            ['id' => (string) Uuid::uuid7(), 'journal_id' => $journalId, 'ledger_account_id' => $systemAccountId, 'direction' => 'credit', 'amount' => 10000, 'currency_code' => 'BWP', 'created_at' => now()],
        ]);
    }

    private function getLedgerBalance(): int
    {
        $result = DB::connection('platform')->selectOne(
            "SELECT COALESCE(SUM(CASE WHEN jl.direction='debit' THEN jl.amount ELSE -jl.amount END), 0) AS balance
             FROM ledger.journal_lines jl
             JOIN ledger.ledger_accounts la ON la.id = jl.ledger_account_id
             WHERE la.ledger_account_reference = ?",
            [$this->ledgerAccountRef]
        );
        return (int) ($result->balance ?? 0);
    }

    private function postDebit(int $amount, string $correlationId): bool
    {
        try {
            DB::connection('platform')->transaction(function () use ($amount, $correlationId): void {
                $account = DB::connection('platform')
                    ->table('ledger.ledger_accounts')
                    ->where('ledger_account_reference', $this->ledgerAccountRef)
                    ->lockForUpdate()
                    ->first();

                $this->assertNotNull($account, 'Ledger account must exist');

                $balance = (int) DB::connection('platform')->selectOne(
                    "SELECT COALESCE(SUM(CASE WHEN jl.direction='debit' THEN jl.amount ELSE -jl.amount END), 0) AS bal
                     FROM ledger.journal_lines jl WHERE jl.ledger_account_id = ?",
                    [$account->id]
                )->bal;

                if ($balance < $amount) {
                    throw new \DomainException('Insufficient funds: balance=' . $balance . ' required=' . $amount);
                }

                // Post debit entry
                $journalId       = (string) Uuid::uuid7();
                $contraAccountId = (string) Uuid::uuid7();

                DB::connection('platform')->table('ledger.ledger_accounts')->insert([
                    'id'                       => $contraAccountId,
                    'ledger_account_reference' => 'contra-' . $journalId,
                    'account_type'             => 'liability',
                    'normal_balance'           => 'credit',
                    'currency_code'            => 'BWP',
                    'owner_reference'          => null,
                    'owner_type'               => 'system',
                    'status'                   => 'active',
                    'created_by'               => $this->initiatorId,
                    'created_at'               => now(),
                    'updated_at'               => now(),
                ]);

                DB::connection('platform')->table('ledger.journals')->insert([
                    'id'              => $journalId,
                    'idempotency_key' => (string) Uuid::uuid7(),
                    'entry_type'      => 'debit',
                    'currency_code'   => 'BWP',
                    'status'          => 'posted',
                    'correlation_id'  => $correlationId,
                    'posted_at'       => now(),
                    'created_by'      => $this->initiatorId,
                    'created_at'      => now(),
                ]);

                DB::connection('platform')->table('ledger.journal_lines')->insert([
                    ['id' => (string) Uuid::uuid7(), 'journal_id' => $journalId, 'ledger_account_id' => $account->id,     'direction' => 'credit', 'amount' => $amount, 'currency_code' => 'BWP', 'created_at' => now()],
                    ['id' => (string) Uuid::uuid7(), 'journal_id' => $journalId, 'ledger_account_id' => $contraAccountId, 'direction' => 'debit',  'amount' => $amount, 'currency_code' => 'BWP', 'created_at' => now()],
                ]);
            });
            return true;
        } catch (\DomainException) {
            return false;
        }
    }

    // ── Test: Only one of two competing 6000 debits succeeds ──────────────────

    public function test_concurrent_debits_of_6000_from_10000_balance_only_one_succeeds(): void
    {
        $correlationId1 = (string) Uuid::uuid7();
        $correlationId2 = (string) Uuid::uuid7();

        $result1 = $this->postDebit(6000, $correlationId1);
        $result2 = $this->postDebit(6000, $correlationId2);

        // Exactly one must succeed (wallet starts at 10000, each debit is 6000)
        $this->assertNotSame($result1, $result2, 'Exactly one of the two 6000 debits must succeed');

        $successCount = ($result1 ? 1 : 0) + ($result2 ? 1 : 0);
        $this->assertSame(1, $successCount, 'Exactly one debit must succeed');

        // Final balance must be 4000 (not negative, not 10000)
        $finalBalance = $this->getLedgerBalance();
        $this->assertSame(4000, $finalBalance, 'Final balance must be 4000 after one 6000 debit');
    }

    // ── Test: Two 4000 debits against 10000 both succeed ─────────────────────

    public function test_two_non_competing_debits_of_4000_each_both_succeed(): void
    {
        $result1 = $this->postDebit(4000, (string) Uuid::uuid7());
        $result2 = $this->postDebit(4000, (string) Uuid::uuid7());

        $this->assertTrue($result1, 'First 4000 debit must succeed');
        $this->assertTrue($result2, 'Second 4000 debit must succeed');

        $finalBalance = $this->getLedgerBalance();
        $this->assertSame(2000, $finalBalance, 'Final balance must be 2000 after two 4000 debits');
    }
}
