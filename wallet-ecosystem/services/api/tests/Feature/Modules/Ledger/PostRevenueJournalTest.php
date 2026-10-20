<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ledger;

use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

/**
 * T048 — US-LEDGER-004: Post revenue journal entry.
 * Covers: revenue entry posts, idempotent replay, unbalanced → 422, transaction_reference present, permission gate.
 */
final class PostRevenueJournalTest extends LedgerTestCase
{
    // Scenario (a): revenue entry posts; entry_type = revenue.
    public function test_revenue_entry_posts_with_correct_entry_type(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/revenue', [
                'currency_code'         => 'BWP',
                'correlation_id'        => (string) Uuid::uuid7(),
                'transaction_reference' => 'REV-' . substr((string) Uuid::uuid7(), 0, 8),
                'lines'                 => [
                    ['ledger_account_reference' => 'acc-revenue-src', 'direction' => 'debit',  'amount' => 300],
                    ['ledger_account_reference' => 'acc-revenue-dst', 'direction' => 'credit', 'amount' => 300],
                ],
            ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.entry_type', 'revenue')
                 ->assertJsonPath('data.status', 'posted');
    }

    // Scenario (b): unbalanced revenue entry → 422 UNBALANCED_JOURNAL.
    public function test_unbalanced_revenue_entry_returns_422(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/revenue', [
                'currency_code'  => 'BWP',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-rev-ub-a', 'direction' => 'debit',  'amount' => 400],
                    ['ledger_account_reference' => 'acc-rev-ub-b', 'direction' => 'credit', 'amount' => 399],
                ],
            ]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'UNBALANCED_JOURNAL');
    }

    // Scenario (c): same Idempotency-Key replayed → 200 with original journal_id.
    public function test_idempotent_replay_returns_200(): void
    {
        $admin   = $this->makeAdminWithPermission('ledger.post');
        $key     = (string) Uuid::uuid7();
        $payload = [
            'currency_code'  => 'BWP',
            'correlation_id' => (string) Uuid::uuid7(),
            'lines'          => [
                ['ledger_account_reference' => 'acc-rev-idem-a', 'direction' => 'debit',  'amount' => 50],
                ['ledger_account_reference' => 'acc-rev-idem-b', 'direction' => 'credit', 'amount' => 50],
            ],
        ];

        $first = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/v1/ledger/entries/revenue', $payload);
        $first->assertStatus(201);

        $second = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/v1/ledger/entries/revenue', $payload);
        $second->assertStatus(200);
        $this->assertSame($first->json('data.journal_id'), $second->json('data.journal_id'));
    }

    // Scenario (d): response includes transaction_reference when provided.
    public function test_response_includes_transaction_reference(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');
        $txRef = 'REV-' . substr((string) Uuid::uuid7(), 0, 8);

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/revenue', [
                'currency_code'         => 'BWP',
                'correlation_id'        => (string) Uuid::uuid7(),
                'transaction_reference' => $txRef,
                'lines'                 => [
                    ['ledger_account_reference' => 'acc-rev-tx-a', 'direction' => 'debit',  'amount' => 100],
                    ['ledger_account_reference' => 'acc-rev-tx-b', 'direction' => 'credit', 'amount' => 100],
                ],
            ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.transaction_reference', $txRef);
    }

    // Scenario (e): actor without ledger.post permission → 403.
    public function test_missing_permission_returns_403(): void
    {
        $noPermActor = $this->makeAdminWithPermission();

        $response = $this->withActorToken($noPermActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/revenue', [
                'currency_code'  => 'BWP',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => 'acc-rev-e-a', 'direction' => 'debit',  'amount' => 100],
                    ['ledger_account_reference' => 'acc-rev-e-b', 'direction' => 'credit', 'amount' => 100],
                ],
            ]);

        $response->assertStatus(403);
    }
}
