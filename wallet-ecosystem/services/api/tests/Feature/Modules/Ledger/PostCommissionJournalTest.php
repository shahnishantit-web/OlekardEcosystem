<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ledger;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\AssertAuditEvent;
use Tests\Support\LedgerTestCase;

/**
 * T050 — US-LEDGER-005: Post commission journal entry.
 * Covers: commission posts, net-zero balance across accounts, auto-register agent account,
 * audit event contains expected action, unbalanced → 422, currency mismatch → 422.
 */
final class PostCommissionJournalTest extends LedgerTestCase
{
    use AssertAuditEvent;

    // Scenario (a): commission entry posts; entry_type = commission; net balance across accounts = 0.
    public function test_commission_entry_posts_and_net_balance_is_zero(): void
    {
        $admin    = $this->makeAdminWithPermission('ledger.post');
        $srcRef   = 'acc-commission-src-' . substr((string) Uuid::uuid7(), 0, 8);
        $agentRef = 'acc-commission-agent-' . substr((string) Uuid::uuid7(), 0, 8);

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/commission', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => $srcRef,   'direction' => 'debit',  'amount' => 250],
                    ['ledger_account_reference' => $agentRef, 'direction' => 'credit', 'amount' => 250],
                ],
            ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.entry_type', 'commission')
                 ->assertJsonPath('data.status', 'posted');

        // Total debit = total credit; net across both lines = 0
        $journalId = $response->json('data.journal_id');
        $debitSum  = DB::connection('platform')
            ->table('ledger.journal_lines')
            ->where('journal_id', $journalId)
            ->where('direction', 'debit')
            ->sum('amount');
        $creditSum = DB::connection('platform')
            ->table('ledger.journal_lines')
            ->where('journal_id', $journalId)
            ->where('direction', 'credit')
            ->sum('amount');
        $this->assertSame((float) $debitSum, (float) $creditSum);
    }

    // Scenario (b): agent account not previously registered → auto-registered; post succeeds.
    public function test_first_commission_to_auto_registered_agent_account_succeeds(): void
    {
        $admin       = $this->makeAdminWithPermission('ledger.post');
        $newAgentRef = 'agent-commission-' . substr((string) Uuid::uuid7(), 0, 8);

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/commission', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-comm-src-b',  'direction' => 'debit',  'amount' => 100],
                    ['ledger_account_reference' => $newAgentRef,       'direction' => 'credit', 'amount' => 100],
                ],
            ]);

        $response->assertStatus(201);

        $this->assertTrue(
            DB::connection('platform')
                ->table('ledger.ledger_accounts')
                ->where('ledger_account_reference', $newAgentRef)
                ->exists(),
        );
    }

    // Scenario (c): audit event contains action = ledger.entry.posted.
    public function test_commission_post_emits_audit_event_with_correct_action(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/commission', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-comm-aud-src', 'direction' => 'debit',  'amount' => 80],
                    ['ledger_account_reference' => 'acc-comm-aud-dst', 'direction' => 'credit', 'amount' => 80],
                ],
            ])
            ->assertStatus(201);

        $this->assertAuditEventRecorded('ledger.entry.posted');
    }

    // Scenario (d): unbalanced commission entry → 422 UNBALANCED_JOURNAL.
    public function test_unbalanced_commission_entry_returns_422(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/commission', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-comm-ub-src', 'direction' => 'debit',  'amount' => 300],
                    ['ledger_account_reference' => 'acc-comm-ub-dst', 'direction' => 'credit', 'amount' => 200],
                ],
            ]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'UNBALANCED_JOURNAL');
    }

    // Scenario (e): currency mismatch between line and request → 422 CURRENCY_MISMATCH.
    public function test_currency_mismatch_returns_422(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/commission', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-comm-cm-src', 'direction' => 'debit',  'amount' => 100, 'currency_code' => 'USD'],
                    ['ledger_account_reference' => 'acc-comm-cm-dst', 'direction' => 'credit', 'amount' => 100],
                ],
            ]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'CURRENCY_MISMATCH');
    }
}
