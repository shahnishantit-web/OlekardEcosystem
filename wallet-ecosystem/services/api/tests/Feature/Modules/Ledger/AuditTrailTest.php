<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ledger;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\AssertAuditEvent;
use Tests\Support\LedgerTestCase;

/**
 * T078 — US-LEDGER-014: Audit trail for all ledger mutations.
 * Verifies audit_events rows are created with correct action, actor_id, resource_id, correlation_id.
 */
final class AuditTrailTest extends LedgerTestCase
{
    use AssertAuditEvent;

    private object $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdminWithPermission(
            'ledger.post',
            'ledger.entry.reverse',
            'ledger.account.register',
        );
    }

    // Scenario (a): journal post → audit event action = ledger.entry.posted.
    public function test_journal_post_records_audit_event(): void
    {
        $correlationId = (string) Uuid::uuid7();

        $response = $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7(), 'X-Correlation-ID' => $correlationId])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'  => 'KES',
                'correlation_id' => $correlationId,
                'lines'          => [
                    ['ledger_account_reference' => 'audit-dr-' . substr((string) Uuid::uuid7(), 0, 8), 'direction' => 'debit',  'amount' => 1000],
                    ['ledger_account_reference' => 'audit-cr-' . substr((string) Uuid::uuid7(), 0, 8), 'direction' => 'credit', 'amount' => 1000],
                ],
            ])
            ->assertStatus(201);

        $journalId = $response->json('data.journal_id');

        $event = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'ledger.entry.posted')
            ->where('resource_id', $journalId)
            ->first();

        $this->assertNotNull($event, 'Expected ledger.entry.posted audit event for journal ' . $journalId);
        $this->assertNotNull($event->actor_id);
        $this->assertNotNull($event->correlation_id);
        $this->assertNotNull($event->occurred_at ?? $event->created_at);
    }

    // Scenario (b): reversal → audit event action = ledger.entry.reversed, both journal IDs in metadata.
    public function test_reversal_records_audit_event_with_both_journal_ids(): void
    {
        $postResponse = $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'rev-audit-dr-' . substr((string) Uuid::uuid7(), 0, 8), 'direction' => 'debit',  'amount' => 2000],
                    ['ledger_account_reference' => 'rev-audit-cr-' . substr((string) Uuid::uuid7(), 0, 8), 'direction' => 'credit', 'amount' => 2000],
                ],
            ])
            ->assertStatus(201);

        $originalId = $postResponse->json('data.journal_id');

        $reverseResponse = $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/' . $originalId . '/reverse', [
                'correlation_id' => (string) Uuid::uuid7(),
            ])
            ->assertStatus(201);

        $reversalId = $reverseResponse->json('data.reversal_journal_id');

        $event = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'ledger.entry.reversed')
            ->where('resource_id', $reversalId)
            ->first();

        $this->assertNotNull($event, 'Expected ledger.entry.reversed audit event for reversal ' . $reversalId);
        $this->assertNotNull($event->actor_id);
        $this->assertNotNull($event->correlation_id);

        // after_state JSON should contain both journal IDs
        $afterState = json_decode($event->after_state ?? '{}', true);
        $this->assertArrayHasKey('reversed_journal_id', $afterState);
        $this->assertSame($originalId, $afterState['reversed_journal_id']);
    }

    // Scenario (c): account registration → audit event action = ledger.account.registered.
    public function test_account_registration_records_audit_event(): void
    {
        $ref = 'audit-reg-' . substr((string) Uuid::uuid7(), 0, 8);

        $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/accounts', [
                'ledger_account_reference' => $ref,
                'account_type'             => 'liability',
                'currency_code'            => 'KES',
                'owner_reference'          => (string) Uuid::uuid7(),
                'owner_type'               => 'customer_pocket',
            ])
            ->assertStatus(201);

        $this->assertAuditEventRecorded('ledger.account.registered');

        $event = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'ledger.account.registered')
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertNotNull($event->actor_id);
        $this->assertNotNull($event->correlation_id);

        $afterState = json_decode($event->after_state ?? '{}', true);
        $this->assertSame($ref, $afterState['ledger_account_reference'] ?? null);
    }

    // Scenario (d): imbalance detection command → audit event action = ledger.imbalance.detected.
    public function test_imbalance_detection_records_audit_event_with_critical_severity(): void
    {
        $orphanAccountId = (string) Uuid::uuid7();
        DB::connection('platform')->table('ledger.ledger_accounts')->insert([
            'id'                       => $orphanAccountId,
            'ledger_account_reference' => 'audit-orphan-' . substr((string) Uuid::uuid7(), 0, 8),
            'account_type'             => 'asset',
            'normal_balance'           => 'debit',
            'currency_code'            => 'EUR',
            'owner_reference'          => null,
            'owner_type'               => null,
            'status'                   => 'active',
            'created_by'               => $this->admin->id,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $orphanJournalId = (string) Uuid::uuid7();
        DB::connection('platform')->table('ledger.journals')->insert([
            'id'                    => $orphanJournalId,
            'idempotency_key'       => 'audit-orphan-key-' . (string) Uuid::uuid7(),
            'entry_type'            => 'debit',
            'status'                => 'posted',
            'currency_code'         => 'EUR',
            'correlation_id'        => (string) Uuid::uuid7(),
            'transaction_reference' => null,
            'reversed_journal_id'   => null,
            'posted_by'             => $this->admin->id,
            'posted_at'             => now(),
            'created_at'            => now(),
        ]);

        DB::connection('platform')->table('ledger.journal_lines')->insert([
            'id'               => (string) Uuid::uuid7(),
            'journal_id'       => $orphanJournalId,
            'ledger_account_id' => $orphanAccountId,
            'direction'        => 'debit',
            'amount'           => 7777,
            'currency_code'    => 'EUR',
            'created_at'       => now(),
        ]);

        $this->artisan('ledger:check-balance')->assertFailed();

        $event = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'ledger.imbalance.detected')
            ->latest('id')
            ->first();

        $this->assertNotNull($event, 'Expected ledger.imbalance.detected audit event');
        $this->assertNotNull($event->correlation_id);

        $afterState = json_decode($event->after_state ?? '{}', true);
        $this->assertSame('CRITICAL', $afterState['severity'] ?? null);
    }

    // Scenario (e): all ledger audit events have non-null actor_id (or system actor) and correlation_id.
    public function test_all_audit_events_have_required_fields(): void
    {
        $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'req-fields-dr-' . substr((string) Uuid::uuid7(), 0, 8), 'direction' => 'debit',  'amount' => 500],
                    ['ledger_account_reference' => 'req-fields-cr-' . substr((string) Uuid::uuid7(), 0, 8), 'direction' => 'credit', 'amount' => 500],
                ],
            ])
            ->assertStatus(201);

        $ledgerActions = ['ledger.entry.posted', 'ledger.account.registered'];

        foreach ($ledgerActions as $action) {
            $events = DB::connection('platform')
                ->table('platform.audit_events')
                ->where('action', $action)
                ->get();

            foreach ($events as $event) {
                $this->assertNotNull($event->correlation_id, "correlation_id must not be null for action {$action}");
            }
        }
    }
}
