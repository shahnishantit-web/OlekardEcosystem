<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ledger;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

/**
 * T043 — US-LEDGER-007: Unbalanced journal rejection and atomicity.
 * Covers: debit/credit sum mismatch → 422, empty lines → 422, mixed currencies → 422,
 * negative amount → 422, rollback on unbalanced (no orphan rows).
 */
final class UnbalancedJournalRejectionTest extends LedgerTestCase
{
    // Scenario (a): debit_total 500 / credit_total 600 → 422 UNBALANCED_JOURNAL; 0 Journal rows.
    public function test_debit_500_credit_600_returns_422_and_no_journals_persisted(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-debit-a7',  'direction' => 'debit',  'amount' => 500],
                    ['ledger_account_reference' => 'acc-credit-a7', 'direction' => 'credit', 'amount' => 600],
                ],
            ]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'UNBALANCED_JOURNAL');

        $this->assertSame(0, DB::connection('platform')->table('ledger.journals')->count());
    }

    // Scenario (b): empty lines array → 422 (FormRequest min:2 validation).
    public function test_empty_lines_array_returns_422(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [],
            ]);

        $response->assertStatus(422);
    }

    // Scenario (c): mixed currencies in lines → 422 CURRENCY_MISMATCH.
    public function test_mixed_currency_lines_return_422_currency_mismatch(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-debit-c7',  'direction' => 'debit',  'amount' => 1000, 'currency_code' => 'USD'],
                    ['ledger_account_reference' => 'acc-credit-c7', 'direction' => 'credit', 'amount' => 1000],
                ],
            ]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'CURRENCY_MISMATCH');
    }

    // Scenario (d): negative amount → 422 (FormRequest integer|min:1 validation).
    public function test_negative_amount_returns_422(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-debit-d7',  'direction' => 'debit',  'amount' => -500],
                    ['ledger_account_reference' => 'acc-credit-d7', 'direction' => 'credit', 'amount' => -500],
                ],
            ]);

        $response->assertStatus(422);
    }

    // Scenario (e): unbalanced journal rejected atomically — no journal_lines orphans.
    public function test_unbalanced_journal_leaves_no_orphan_lines(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-debit-e7',  'direction' => 'debit',  'amount' => 300],
                    ['ledger_account_reference' => 'acc-credit-e7', 'direction' => 'credit', 'amount' => 400],
                ],
            ]);

        $response->assertStatus(422);

        $this->assertSame(0, DB::connection('platform')->table('ledger.journals')->count());
        $this->assertSame(0, DB::connection('platform')->table('ledger.journal_lines')->count());
    }

    // Unauthenticated request → 401.
    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/v1/ledger/entries/debit', [
            'currency_code'  => 'KES',
            'correlation_id' => (string) Uuid::uuid7(),
            'lines'          => [
                ['ledger_account_reference' => 'acc-x', 'direction' => 'debit',  'amount' => 100],
                ['ledger_account_reference' => 'acc-y', 'direction' => 'credit', 'amount' => 100],
            ],
        ]);

        $response->assertStatus(401);
    }
}
