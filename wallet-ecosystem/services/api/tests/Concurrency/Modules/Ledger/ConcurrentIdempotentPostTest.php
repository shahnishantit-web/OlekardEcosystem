<?php

declare(strict_types=1);

namespace Tests\Concurrency\Modules\Ledger;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

/**
 * CC-E07-003 / T080 — 10 requests with same Idempotency-Key → exactly 1 journal record.
 *
 * True multi-process concurrency requires separate PHP processes and CLI runners.
 * This test serialises the requests on one connection and verifies the idempotency
 * guard (Redis fast path + PostgreSQL UNIQUE constraint) prevents duplicate journals.
 * The serial enforcement here covers the "second caller sees a completed idempotency
 * record and returns the cached response" code path.
 */
final class ConcurrentIdempotentPostTest extends LedgerTestCase
{
    // CC-E07-003: 10 requests with same Idempotency-Key → exactly 1 journal row; 9 replays.
    public function test_ten_requests_with_same_key_produce_exactly_one_journal(): void
    {
        $admin         = $this->makeAdminWithPermission('ledger.post');
        $idempotencyKey = (string) Uuid::uuid7();
        $debitRef       = 'idem-dr-' . substr((string) Uuid::uuid7(), 0, 8);
        $creditRef      = 'idem-cr-' . substr((string) Uuid::uuid7(), 0, 8);

        $payload = [
            'currency_code'  => 'KES',
            'correlation_id' => (string) Uuid::uuid7(),
            'lines'          => [
                ['ledger_account_reference' => $debitRef,  'direction' => 'debit',  'amount' => 1000],
                ['ledger_account_reference' => $creditRef, 'direction' => 'credit', 'amount' => 1000],
            ],
        ];

        $responses = [];
        for ($i = 0; $i < 10; $i++) {
            $responses[] = $this->withActorToken($admin)
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->postJson('/v1/ledger/entries/debit', $payload);
        }

        // First response is 201, all subsequent are 200 (replay)
        $statuses = array_map(fn ($r) => $r->status(), $responses);

        $created  = array_filter($statuses, fn ($s) => $s === 201);
        $replayed = array_filter($statuses, fn ($s) => $s === 200);

        $this->assertSame(1, count($created),  'Exactly 1 request should return HTTP 201');
        $this->assertSame(9, count($replayed), '9 requests should return HTTP 200 (replay)');

        // All 10 must return the same journal_id
        $journalIds = array_unique(array_map(fn ($r) => $r->json('data.journal_id'), $responses));
        $this->assertCount(1, $journalIds, 'All responses must share the same journal_id');

        // Exactly 1 row in journals table
        $count = DB::connection('platform')
            ->table('ledger.journals')
            ->where('idempotency_key', $idempotencyKey)
            ->count();

        $this->assertSame(1, $count, 'Exactly 1 journal record must exist for the idempotency key');
    }
}
