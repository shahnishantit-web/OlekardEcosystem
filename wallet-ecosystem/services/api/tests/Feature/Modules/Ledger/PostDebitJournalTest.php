<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ledger;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\AssertOutboxEvent;
use Tests\Support\LedgerTestCase;

/**
 * T040 — US-LEDGER-001: Post debit journal entry.
 * Covers: balanced post → 201, idempotent replay → 200, missing key → 422, no perm → 403, outbox event.
 */
final class PostDebitJournalTest extends LedgerTestCase
{
    use AssertOutboxEvent;

    // Scenario (a): balanced debit post returns 201 with journal_id; outbox event written.
    public function test_balanced_debit_post_returns_201_with_journal_id_and_outbox_event(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'         => 'KES',
                'correlation_id'        => (string) Uuid::uuid7(),
                'transaction_reference' => 'TXN-' . substr((string) Uuid::uuid7(), 0, 8),
                'lines'                 => [
                    ['ledger_account_reference' => 'acc-debit-a', 'direction' => 'debit',  'amount' => 1000],
                    ['ledger_account_reference' => 'acc-credit-a', 'direction' => 'credit', 'amount' => 1000],
                ],
            ]);

        $response->assertStatus(201)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.entry_type', 'debit')
                 ->assertJsonPath('data.status', 'posted')
                 ->assertJsonStructure(['data' => ['journal_id', 'entry_type', 'status', 'posted_at']]);

        $this->assertNotNull($response->json('data.journal_id'));

        $this->assertOutboxEventPublished('Ledger.Entry.Posted');
    }

    // Scenario (b): same Idempotency-Key replayed returns 200 with same journal_id; no duplicate row.
    public function test_idempotent_replay_returns_200_with_same_journal_id(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');
        $key   = (string) Uuid::uuid7();

        $payload = [
            'currency_code'  => 'KES',
            'correlation_id' => (string) Uuid::uuid7(),
            'lines'          => [
                ['ledger_account_reference' => 'acc-debit-b', 'direction' => 'debit',  'amount' => 500],
                ['ledger_account_reference' => 'acc-credit-b', 'direction' => 'credit', 'amount' => 500],
            ],
        ];

        $first = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/v1/ledger/entries/debit', $payload);

        $first->assertStatus(201);
        $originalJournalId = $first->json('data.journal_id');

        $second = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/v1/ledger/entries/debit', $payload);

        $second->assertStatus(200);
        $this->assertSame($originalJournalId, $second->json('data.journal_id'));

        $journalCount = DB::connection('platform')
            ->table('ledger.journals')
            ->where('idempotency_key', $key)
            ->count();
        $this->assertSame(1, $journalCount);
    }

    // Scenario (c): missing Idempotency-Key header returns 422 MISSING_IDEMPOTENCY_KEY.
    public function test_missing_idempotency_key_returns_422(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-debit-c', 'direction' => 'debit',  'amount' => 1000],
                    ['ledger_account_reference' => 'acc-credit-c', 'direction' => 'credit', 'amount' => 1000],
                ],
            ]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'MISSING_IDEMPOTENCY_KEY');
    }

    // Scenario (d): actor without ledger.post permission receives 403.
    public function test_actor_without_permission_receives_403(): void
    {
        $noPermActor = $this->makeAdminWithPermission(); // no permissions granted

        $response = $this->withActorToken($noPermActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-debit-d', 'direction' => 'debit',  'amount' => 1000],
                    ['ledger_account_reference' => 'acc-credit-d', 'direction' => 'credit', 'amount' => 1000],
                ],
            ]);

        $response->assertStatus(403);
    }

    // Scenario (e): two distinct Idempotency-Keys create two separate journal rows.
    public function test_distinct_idempotency_keys_create_separate_journals(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $payload = [
            'currency_code'  => 'KES',
            'correlation_id' => (string) Uuid::uuid7(),
            'lines'          => [
                ['ledger_account_reference' => 'acc-debit-e', 'direction' => 'debit',  'amount' => 200],
                ['ledger_account_reference' => 'acc-credit-e', 'direction' => 'credit', 'amount' => 200],
            ],
        ];

        $r1 = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', $payload);
        $r1->assertStatus(201);

        $r2 = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', $payload);
        $r2->assertStatus(201);

        $this->assertNotSame($r1->json('data.journal_id'), $r2->json('data.journal_id'));

        $journalCount = DB::connection('platform')->table('ledger.journals')->count();
        $this->assertSame(2, $journalCount);
    }
}
