<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ledger;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\AssertOutboxEvent;
use Tests\Support\LedgerTestCase;

/**
 * T046 — US-LEDGER-003: Post fee journal entry.
 * Covers: fee entry posts normally, unknown fee account auto-registered,
 * DB rollback leaves no orphans, permission gate, zero amount → 422.
 */
final class PostFeeJournalTest extends LedgerTestCase
{
    use AssertOutboxEvent;

    // Scenario (a): fee entry posts with entry_type = fee → 201.
    public function test_fee_entry_posts_with_correct_entry_type(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/fee', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-customer-pocket', 'direction' => 'debit',  'amount' => 150],
                    ['ledger_account_reference' => 'acc-fee-account',     'direction' => 'credit', 'amount' => 150],
                ],
            ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.entry_type', 'fee')
                 ->assertJsonPath('data.status', 'posted');

        $journalId = $response->json('data.journal_id');
        $journal   = DB::connection('platform')
            ->table('ledger.journals')
            ->where('id', $journalId)
            ->first();

        $this->assertNotNull($journal);
        $this->assertSame('fee', $journal->entry_type);
    }

    // Scenario (b): DB rollback on unbalanced fee → no journal, no lines persisted.
    public function test_unbalanced_fee_entry_leaves_no_orphans(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/fee', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-customer-ub', 'direction' => 'debit',  'amount' => 150],
                    ['ledger_account_reference' => 'acc-fee-ub',      'direction' => 'credit', 'amount' => 100],
                ],
            ]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'UNBALANCED_JOURNAL');

        $this->assertSame(0, DB::connection('platform')->table('ledger.journals')->count());
        $this->assertSame(0, DB::connection('platform')->table('ledger.journal_lines')->count());
    }

    // Scenario (c): unknown fee account reference auto-registered; post succeeds.
    public function test_unknown_fee_account_auto_registered_and_post_succeeds(): void
    {
        $admin      = $this->makeAdminWithPermission('ledger.post');
        $newFeeRef  = 'fee-account-' . substr((string) Uuid::uuid7(), 0, 8);

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/fee', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-customer-nr', 'direction' => 'debit',  'amount' => 75],
                    ['ledger_account_reference' => $newFeeRef,         'direction' => 'credit', 'amount' => 75],
                ],
            ]);

        $response->assertStatus(201);

        $this->assertTrue(
            DB::connection('platform')
                ->table('ledger.ledger_accounts')
                ->where('ledger_account_reference', $newFeeRef)
                ->exists(),
        );
    }

    // Scenario (d): actor without ledger.post permission → 403.
    public function test_actor_without_permission_returns_403(): void
    {
        $noPermActor = $this->makeAdminWithPermission();

        $response = $this->withActorToken($noPermActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/fee', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-d', 'direction' => 'debit',  'amount' => 50],
                    ['ledger_account_reference' => 'acc-c', 'direction' => 'credit', 'amount' => 50],
                ],
            ]);

        $response->assertStatus(403);
    }

    // Scenario (e): zero amount → 422 FormRequest validation.
    public function test_zero_amount_returns_422(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/fee', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-fee-e', 'direction' => 'debit',  'amount' => 0],
                    ['ledger_account_reference' => 'acc-src-e', 'direction' => 'credit', 'amount' => 0],
                ],
            ]);

        $response->assertStatus(422);
    }
}
