<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Ledger;

use App\Modules\Ledger\Application\Services\LedgerBalanceService;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\TestCase;

/**
 * T091 — FR-E07-027: entryExists() returns true iff a posted journal exists for the reference.
 *
 * Covers:
 *  (a) Returns true when a posted journal with the given transaction_reference exists.
 *  (b) Returns false for an unknown reference.
 *  (c) Returns true when the original journal exists and a reversal journal has also been
 *      posted for the same transaction_reference (authoritative semantics for E11 callers:
 *      entryExists checks for any posted journal, including reversals).
 */
final class EntryExistsTest extends TestCase
{
    private LedgerBalanceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $metrics       = $this->createStub(MetricsCollectorInterface::class);
        $this->service = new LedgerBalanceService($metrics);
    }

    public function test_returns_true_when_posted_journal_exists_for_reference(): void
    {
        $ref = 'TXN-' . substr((string) Uuid::uuid7(), 0, 8);
        $this->insertJournal($ref, 'debit', 'posted');

        $this->assertTrue($this->service->entryExists($ref));
    }

    public function test_returns_false_for_unknown_reference(): void
    {
        $this->assertFalse($this->service->entryExists('TXN-DOES-NOT-EXIST-' . Uuid::uuid7()));
    }

    public function test_returns_true_when_both_original_and_reversal_posted_for_same_reference(): void
    {
        $ref    = 'TXN-' . substr((string) Uuid::uuid7(), 0, 8);
        $origId = $this->insertJournal($ref, 'debit', 'posted');
        $this->insertJournal($ref, 'reversal', 'posted', $origId);

        $this->assertTrue($this->service->entryExists($ref));
    }

    private function insertJournal(
        string $transactionReference,
        string $entryType,
        string $status,
        ?string $reversedJournalId = null,
    ): string {
        $id = (string) Uuid::uuid7();

        DB::connection('platform')->table('ledger.journals')->insert([
            'id'                    => $id,
            'idempotency_key'       => (string) Uuid::uuid7(),
            'entry_type'            => $entryType,
            'currency_code'         => 'KES',
            'status'                => $status,
            'transaction_reference' => $transactionReference,
            'reversed_journal_id'   => $reversedJournalId,
            'correlation_id'        => (string) Uuid::uuid7(),
            'description'           => null,
            'posted_at'             => now(),
            'created_by'            => (string) Uuid::uuid7(),
            'created_at'            => now(),
        ]);

        return $id;
    }
}
