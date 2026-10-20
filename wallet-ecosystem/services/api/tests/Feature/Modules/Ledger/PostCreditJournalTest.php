<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ledger;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

/**
 * T041 — US-LEDGER-002: Post credit journal entry.
 * Covers: balanced credit → 201, auto-register account, zero amount → 422, currency mismatch → 422,
 * two distinct keys → two separate journals.
 */
final class PostCreditJournalTest extends LedgerTestCase
{
    // Scenario (a): balanced credit post returns 201; journal row persisted.
    public function test_balanced_credit_post_returns_201(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/credit', [
                'currency_code'  => 'BWP',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-credit-aa', 'direction' => 'credit', 'amount' => 2000],
                    ['ledger_account_reference' => 'acc-debit-aa',  'direction' => 'debit',  'amount' => 2000],
                ],
            ]);

        $response->assertStatus(201)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.entry_type', 'credit')
                 ->assertJsonPath('data.status', 'posted');

        $this->assertNotNull($response->json('data.journal_id'));

        $journalCount = DB::connection('platform')->table('ledger.journals')->count();
        $this->assertSame(1, $journalCount);
    }

    // Scenario (b): credit to a previously-unknown account auto-registers that account.
    public function test_credit_to_unknown_account_auto_registers_it(): void
    {
        $admin   = $this->makeAdminWithPermission('ledger.post');
        $freshRef = 'fresh-account-' . substr((string) Uuid::uuid7(), 0, 8);

        $existsBefore = DB::connection('platform')
            ->table('ledger.ledger_accounts')
            ->where('ledger_account_reference', $freshRef)
            ->exists();
        $this->assertFalse($existsBefore);

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/credit', [
                'currency_code'  => 'BWP',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => $freshRef,        'direction' => 'credit', 'amount' => 500],
                    ['ledger_account_reference' => 'acc-offset-bb',  'direction' => 'debit',  'amount' => 500],
                ],
            ]);

        $response->assertStatus(201);

        $existsAfter = DB::connection('platform')
            ->table('ledger.ledger_accounts')
            ->where('ledger_account_reference', $freshRef)
            ->exists();
        $this->assertTrue($existsAfter);
    }

    // Scenario (c): line with amount = 0 fails FormRequest validation → 422.
    public function test_zero_amount_line_returns_422(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/credit', [
                'currency_code'  => 'BWP',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-credit-cc', 'direction' => 'credit', 'amount' => 0],
                    ['ledger_account_reference' => 'acc-debit-cc',  'direction' => 'debit',  'amount' => 1000],
                ],
            ]);

        $response->assertStatus(422);
    }

    // Scenario (d): line-level currency mismatch returns 422 CURRENCY_MISMATCH.
    public function test_line_currency_mismatch_returns_422(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/credit', [
                'currency_code'  => 'BWP',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-credit-dd', 'direction' => 'credit', 'amount' => 1000, 'currency_code' => 'USD'],
                    ['ledger_account_reference' => 'acc-debit-dd',  'direction' => 'debit',  'amount' => 1000],
                ],
            ]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'CURRENCY_MISMATCH');
    }

    // Scenario (e): two distinct Idempotency-Keys with same payload create two separate journals.
    public function test_two_distinct_keys_create_separate_journals(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $payload = [
            'currency_code'  => 'BWP',
            'correlation_id' => (string) Uuid::uuid7(),
            'lines'          => [
                ['ledger_account_reference' => 'acc-credit-ee', 'direction' => 'credit', 'amount' => 100],
                ['ledger_account_reference' => 'acc-debit-ee',  'direction' => 'debit',  'amount' => 100],
            ],
        ];

        $r1 = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/credit', $payload);
        $r1->assertStatus(201);

        $r2 = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/credit', $payload);
        $r2->assertStatus(201);

        $this->assertNotSame($r1->json('data.journal_id'), $r2->json('data.journal_id'));

        $journalCount = DB::connection('platform')->table('ledger.journals')->count();
        $this->assertSame(2, $journalCount);
    }
}
