<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ledger;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\AssertAuditEvent;
use Tests\Support\AssertOutboxEvent;
use Tests\Support\LedgerTestCase;

/**
 * T057 — US-LEDGER-009: Reverse a posted journal via compensating journal.
 * Covers: reversal journal created with mirrored lines, original unchanged,
 * double-reversal rejected, permission gate, idempotent replay, audit event.
 */
final class ReverseJournalTest extends LedgerTestCase
{
    use AssertOutboxEvent;
    use AssertAuditEvent;

    private function postBalancedJournal(object $actor, string $currencyCode = 'KES'): string
    {
        $response = $this->withActorToken($actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'         => $currencyCode,
                'correlation_id'        => (string) Uuid::uuid7(),
                'transaction_reference' => 'TXN-REV-' . substr((string) Uuid::uuid7(), 0, 8),
                'lines'                 => [
                    ['ledger_account_reference' => 'acc-rev-debit-' . substr((string) Uuid::uuid7(), 0, 6),  'direction' => 'debit',  'amount' => 1000],
                    ['ledger_account_reference' => 'acc-rev-credit-' . substr((string) Uuid::uuid7(), 0, 6), 'direction' => 'credit', 'amount' => 1000],
                ],
            ]);
        $response->assertStatus(201);
        return $response->json('data.journal_id');
    }

    // Scenario (a): reverse J1 → J2 has mirrored lines; J2.reversed_journal_id = J1.id; J1 unchanged.
    public function test_reversal_creates_journal_with_mirrored_lines(): void
    {
        $admin    = $this->makeAdminWithPermission('ledger.post', 'ledger.entry.reverse');
        $j1Id     = $this->postBalancedJournal($admin);
        $revKey   = (string) Uuid::uuid7();

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => $revKey])
            ->postJson('/v1/ledger/entries/' . $j1Id . '/reverse', [
                'correlation_id' => (string) Uuid::uuid7(),
            ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.reversed_journal_id', $j1Id)
                 ->assertJsonPath('data.status', 'posted');

        $j2Id = $response->json('data.reversal_journal_id');
        $this->assertNotNull($j2Id);
        $this->assertNotSame($j1Id, $j2Id);

        // J2 has reversed_journal_id = J1
        $j2 = DB::connection('platform')->table('ledger.journals')->where('id', $j2Id)->first();
        $this->assertSame($j1Id, $j2->reversed_journal_id);
        $this->assertSame('reversal', $j2->entry_type);

        // J1 is unchanged
        $j1 = DB::connection('platform')->table('ledger.journals')->where('id', $j1Id)->first();
        $this->assertSame('debit', $j1->entry_type);
        $this->assertNull($j1->reversed_journal_id);

        // J2 has same number of lines as J1
        $j1LineCount = DB::connection('platform')->table('ledger.journal_lines')->where('journal_id', $j1Id)->count();
        $j2LineCount = DB::connection('platform')->table('ledger.journal_lines')->where('journal_id', $j2Id)->count();
        $this->assertSame($j1LineCount, $j2LineCount);

        // J2 lines have opposite directions to J1 lines
        $j1DebitSum  = DB::connection('platform')->table('ledger.journal_lines')->where('journal_id', $j1Id)->where('direction', 'debit')->sum('amount');
        $j2CreditSum = DB::connection('platform')->table('ledger.journal_lines')->where('journal_id', $j2Id)->where('direction', 'credit')->sum('amount');
        $this->assertEquals($j1DebitSum, $j2CreditSum);
    }

    // Scenario (b): second reversal of same journal → 422 JOURNAL_ALREADY_REVERSED.
    public function test_double_reversal_returns_422(): void
    {
        $admin  = $this->makeAdminWithPermission('ledger.post', 'ledger.entry.reverse');
        $j1Id   = $this->postBalancedJournal($admin);

        $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/' . $j1Id . '/reverse', ['correlation_id' => (string) Uuid::uuid7()])
            ->assertStatus(201);

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/' . $j1Id . '/reverse', ['correlation_id' => (string) Uuid::uuid7()]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'JOURNAL_ALREADY_REVERSED');
    }

    // Scenario (c): actor without ledger.entry.reverse permission → 403.
    public function test_missing_reverse_permission_returns_403(): void
    {
        $poster  = $this->makeAdminWithPermission('ledger.post');
        $j1Id    = $this->postBalancedJournal($poster);

        $noRevActor = $this->makeAdminWithPermission('ledger.post'); // no entry.reverse

        $response = $this->withActorToken($noRevActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/' . $j1Id . '/reverse', ['correlation_id' => (string) Uuid::uuid7()]);

        $response->assertStatus(403);
    }

    // Scenario (d): same reversal Idempotency-Key replayed → 200, original reversal journal_id.
    public function test_idempotent_reversal_replay_returns_200(): void
    {
        $admin  = $this->makeAdminWithPermission('ledger.post', 'ledger.entry.reverse');
        $j1Id   = $this->postBalancedJournal($admin);
        $revKey = (string) Uuid::uuid7();

        $first = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => $revKey])
            ->postJson('/v1/ledger/entries/' . $j1Id . '/reverse', ['correlation_id' => (string) Uuid::uuid7()]);
        $first->assertStatus(201);
        $reversalId = $first->json('data.reversal_journal_id');

        $second = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => $revKey])
            ->postJson('/v1/ledger/entries/' . $j1Id . '/reverse', ['correlation_id' => (string) Uuid::uuid7()]);
        $second->assertStatus(200);
        $this->assertSame($reversalId, $second->json('data.reversal_journal_id'));
    }

    // Scenario (e): audit event for reversal contains action = ledger.entry.reversed.
    public function test_reversal_emits_audit_event_with_correct_action(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post', 'ledger.entry.reverse');
        $j1Id  = $this->postBalancedJournal($admin);

        $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/' . $j1Id . '/reverse', ['correlation_id' => (string) Uuid::uuid7()])
            ->assertStatus(201);

        $this->assertAuditEventRecorded('ledger.entry.reversed');
        $this->assertOutboxEventPublished('Ledger.Entry.Reversed');
    }

    // Scenario (g): attempting to reverse a reversal journal (J2) → 422 JOURNAL_ALREADY_REVERSED.
    // FR-E07-031: a reversal journal itself cannot be reversed.
    public function test_reversing_a_reversal_journal_returns_422(): void
    {
        $admin  = $this->makeAdminWithPermission('ledger.post', 'ledger.entry.reverse');
        $j1Id   = $this->postBalancedJournal($admin);

        // Reverse J1 → produces J2 (entry_type = reversal)
        $reverseResponse = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/' . $j1Id . '/reverse', ['correlation_id' => (string) Uuid::uuid7()]);
        $reverseResponse->assertStatus(201);
        $j2Id = $reverseResponse->json('data.reversal_journal_id');

        // Attempt to reverse J2 (the reversal journal itself)
        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/' . $j2Id . '/reverse', ['correlation_id' => (string) Uuid::uuid7()]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'JOURNAL_ALREADY_REVERSED');
    }

    // Scenario (f): reversing a non-existent journal → 404 JOURNAL_NOT_FOUND.
    public function test_reversal_of_nonexistent_journal_returns_404(): void
    {
        $admin       = $this->makeAdminWithPermission('ledger.entry.reverse');
        $nonExistent = (string) Uuid::uuid7();

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/' . $nonExistent . '/reverse', ['correlation_id' => (string) Uuid::uuid7()]);

        $response->assertStatus(404)
                 ->assertJsonPath('error.code', 'JOURNAL_NOT_FOUND');
    }
}
