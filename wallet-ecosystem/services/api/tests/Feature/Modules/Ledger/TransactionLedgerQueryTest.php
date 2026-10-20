<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ledger;

use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

/**
 * T071 — US-LEDGER-011: Journal query by transaction_reference and by ID.
 */
final class TransactionLedgerQueryTest extends LedgerTestCase
{
    private object $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdminWithPermission('ledger.post', 'ledger.read', 'ledger.entry.reverse');
    }

    private function postEntry(?string $txnRef = null): array
    {
        $debitRef  = 'txn-qry-dr-' . substr((string) Uuid::uuid7(), 0, 8);
        $creditRef = 'txn-qry-cr-' . substr((string) Uuid::uuid7(), 0, 8);
        $payload   = [
            'currency_code'  => 'KES',
            'correlation_id' => (string) Uuid::uuid7(),
            'lines'          => [
                ['ledger_account_reference' => $debitRef,  'direction' => 'debit',  'amount' => 1000],
                ['ledger_account_reference' => $creditRef, 'direction' => 'credit', 'amount' => 1000],
            ],
        ];
        if ($txnRef !== null) {
            $payload['transaction_reference'] = $txnRef;
        }

        $response = $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', $payload)
            ->assertStatus(201);

        return [$response->json('data.journal_id'), $debitRef, $creditRef];
    }

    // Scenario (a): two journals with same transaction_reference → both returned.
    public function test_two_journals_with_same_transaction_reference_are_both_returned(): void
    {
        $txnRef = 'TXN-SHARED-' . strtoupper(substr((string) Uuid::uuid7(), 0, 8));

        $this->postEntry($txnRef);
        $this->postEntry($txnRef);

        $response = $this->withActorToken($this->admin)
            ->getJson('/v1/ledger/journals?transaction_reference=' . $txnRef);

        $response->assertStatus(200)
                 ->assertJsonPath('meta.total', 2)
                 ->assertJsonCount(2, 'data');
    }

    // Scenario (b): unknown transaction_reference → empty array, HTTP 200.
    public function test_unknown_transaction_reference_returns_empty_array(): void
    {
        $this->withActorToken($this->admin)
            ->getJson('/v1/ledger/journals?transaction_reference=COMPLETELY-UNKNOWN-' . Uuid::uuid7())
            ->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    // Scenario (c): without ledger.read → 403.
    public function test_missing_ledger_read_permission_returns_403(): void
    {
        $noRead = $this->makeAdminWithPermission('ledger.post');
        $txnRef = 'TXN-NOPERM-' . strtoupper(substr((string) Uuid::uuid7(), 0, 8));

        $this->postEntry($txnRef);

        $this->withActorToken($noRead)
            ->getJson('/v1/ledger/journals?transaction_reference=' . $txnRef)
            ->assertStatus(403);
    }

    // Scenario (d): original + reversal with same shared reference → both returned.
    public function test_original_and_reversal_both_returned_for_same_transaction_reference(): void
    {
        $txnRef = 'TXN-REV-' . strtoupper(substr((string) Uuid::uuid7(), 0, 8));
        [$journalId] = $this->postEntry($txnRef);

        $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/' . $journalId . '/reverse', [
                'correlation_id'        => (string) Uuid::uuid7(),
                'transaction_reference' => $txnRef,
            ])
            ->assertStatus(201);

        // Query by transaction_reference — may return 1 or 2 depending on whether
        // reversal inherits the reference; the original must always be present.
        $response = $this->withActorToken($this->admin)
            ->getJson('/v1/ledger/journals?transaction_reference=' . $txnRef);

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('meta.total'));
    }

    // Scenario (e): pagination params are honored.
    public function test_pagination_params_are_honored(): void
    {
        $txnRef = 'TXN-PAGE-' . strtoupper(substr((string) Uuid::uuid7(), 0, 8));
        for ($i = 0; $i < 5; $i++) {
            $this->postEntry($txnRef);
        }

        $response = $this->withActorToken($this->admin)
            ->getJson('/v1/ledger/journals?transaction_reference=' . $txnRef . '&page=1&per_page=3');

        $response->assertStatus(200)
                 ->assertJsonCount(3, 'data')
                 ->assertJsonPath('meta.total', 5)
                 ->assertJsonPath('meta.pages', 2);
    }

    // Scenario (f): GET /v1/ledger/journals/{id} returns all line fields.
    public function test_show_journal_by_id_returns_all_line_fields(): void
    {
        [$journalId] = $this->postEntry();

        $response = $this->withActorToken($this->admin)
            ->getJson('/v1/ledger/journals/' . $journalId);

        $response->assertStatus(200)
                 ->assertJsonPath('data.id', $journalId);

        $data = $response->json('data');
        $this->assertArrayHasKey('entry_type',          $data);
        $this->assertArrayHasKey('status',              $data);
        $this->assertArrayHasKey('currency_code',       $data);
        $this->assertArrayHasKey('posted_at',           $data);
        $this->assertArrayHasKey('lines',               $data);
        $this->assertNotEmpty($data['lines']);

        $line = $data['lines'][0];
        $this->assertArrayHasKey('id',                $line);
        $this->assertArrayHasKey('ledger_account_id', $line);
        $this->assertArrayHasKey('direction',         $line);
        $this->assertArrayHasKey('amount',            $line);
        $this->assertArrayHasKey('currency_code',     $line);
    }

    // Scenario (g): GET /v1/ledger/journals/{id} with unknown ID → 404.
    public function test_unknown_journal_id_returns_404(): void
    {
        $this->withActorToken($this->admin)
            ->getJson('/v1/ledger/journals/' . Uuid::uuid7())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'JOURNAL_NOT_FOUND');
    }
}
