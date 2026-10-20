<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ledger;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

/**
 * T042 — US-LEDGER-006: Balanced journal enforcement.
 * Covers: three-line balanced journal commits, SUM mismatch → 422, one-sided journal → 422,
 * zero amounts → 422, no lines persisted on failure.
 */
final class BalancedJournalEnforcementTest extends LedgerTestCase
{
    // Scenario (a): three-line journal (debit 1000, credit 600, credit 400) commits successfully.
    public function test_three_line_balanced_journal_commits(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-debit-3l',   'direction' => 'debit',  'amount' => 1000],
                    ['ledger_account_reference' => 'acc-credit-3l-b', 'direction' => 'credit', 'amount' => 600],
                    ['ledger_account_reference' => 'acc-credit-3l-c', 'direction' => 'credit', 'amount' => 400],
                ],
            ]);

        $response->assertStatus(201);

        $journalId    = $response->json('data.journal_id');
        $lineCount    = DB::connection('platform')
            ->table('ledger.journal_lines')
            ->where('journal_id', $journalId)
            ->count();
        $this->assertSame(3, $lineCount);
    }

    // Scenario (b): SUM(debits)=1000 vs SUM(credits)=999 → 422 UNBALANCED_JOURNAL; 0 lines persisted.
    public function test_unequal_debit_credit_totals_returns_422_and_no_lines_persisted(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-debit-ub',  'direction' => 'debit',  'amount' => 1000],
                    ['ledger_account_reference' => 'acc-credit-ub', 'direction' => 'credit', 'amount' => 999],
                ],
            ]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'UNBALANCED_JOURNAL');

        $lineCount = DB::connection('platform')->table('ledger.journal_lines')->count();
        $this->assertSame(0, $lineCount);
    }

    // Scenario (c): only debit lines (no credits) → 422 UNBALANCED_JOURNAL.
    public function test_debit_only_journal_returns_422(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-debit-only-1', 'direction' => 'debit', 'amount' => 500],
                    ['ledger_account_reference' => 'acc-debit-only-2', 'direction' => 'debit', 'amount' => 500],
                ],
            ]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'UNBALANCED_JOURNAL');
    }

    // Scenario (d): all amounts = 0 → 422 (FormRequest min:1 validation).
    public function test_zero_amounts_return_422(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-zero-1', 'direction' => 'debit',  'amount' => 0],
                    ['ledger_account_reference' => 'acc-zero-2', 'direction' => 'credit', 'amount' => 0],
                ],
            ]);

        $response->assertStatus(422);
    }

    // Scenario (e): balanced posts from N sequential requests all succeed; journal count equals N.
    public function test_multiple_sequential_balanced_posts_all_succeed(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');
        $n     = 5;

        for ($i = 0; $i < $n; $i++) {
            $r = $this->withActorToken($admin)
                ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
                ->postJson('/v1/ledger/entries/debit', [
                    'currency_code'  => 'KES',
                    'correlation_id' => (string) Uuid::uuid7(),
                    'lines'          => [
                        ['ledger_account_reference' => 'acc-seq-debit',  'direction' => 'debit',  'amount' => 100],
                        ['ledger_account_reference' => 'acc-seq-credit', 'direction' => 'credit', 'amount' => 100],
                    ],
                ]);
            $r->assertStatus(201);
        }

        $journalCount = DB::connection('platform')->table('ledger.journals')->count();
        $this->assertSame($n, $journalCount);
    }
}
