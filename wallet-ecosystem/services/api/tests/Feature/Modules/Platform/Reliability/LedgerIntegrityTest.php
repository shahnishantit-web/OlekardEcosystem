<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Platform\Reliability;

use App\Jobs\LedgerIntegrityJob;
use App\Modules\Platform\Infrastructure\Models\LedgerIntegritySnapshotModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

/**
 * E27 — FR-009, SC-006: Ledger integrity scan correctness.
 *
 * Tests:
 * 1. Balanced journals → snapshot outcome=clean, discrepancy_count=0
 * 2. Artificially imbalanced journal → outcome=discrepancy_found + audit event
 * 3. In-flight (recently posted) journals excluded from scan
 */
final class LedgerIntegrityTest extends LedgerTestCase
{
    protected $connectionsToTransact = [null, 'identity', 'platform', 'ledger'];

    private string $correlationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->correlationId = (string) Uuid::uuid7();

        // Truncate snapshots table for clean assertions
        DB::connection('platform')->table('platform.ledger_integrity_snapshots')->truncate();
    }

    private function insertBalancedJournal(): string
    {
        $journalId = (string) Uuid::uuid7();
        $accountId = (string) Uuid::uuid7();

        // Create a ledger account
        DB::connection('platform')->table('ledger.ledger_accounts')->insertOrIgnore([
            'id'                       => $accountId,
            'ledger_account_reference' => 'test-acct-' . $accountId,
            'account_type'             => 'asset',
            'normal_balance'           => 'debit',
            'currency_code'            => 'BWP',
            'owner_reference'          => (string) Uuid::uuid7(),
            'owner_type'               => 'wallet',
            'status'                   => 'active',
            'created_by'               => (string) Uuid::uuid7(),
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $creditAccountId = (string) Uuid::uuid7();
        DB::connection('platform')->table('ledger.ledger_accounts')->insertOrIgnore([
            'id'                       => $creditAccountId,
            'ledger_account_reference' => 'test-acct-credit-' . $creditAccountId,
            'account_type'             => 'liability',
            'normal_balance'           => 'credit',
            'currency_code'            => 'BWP',
            'owner_reference'          => null,
            'owner_type'               => 'system',
            'status'                   => 'active',
            'created_by'               => (string) Uuid::uuid7(),
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        DB::connection('platform')->table('ledger.journals')->insert([
            'id'                    => $journalId,
            'idempotency_key'       => (string) Uuid::uuid7(),
            'entry_type'            => 'debit',
            'currency_code'         => 'BWP',
            'status'                => 'posted',
            'correlation_id'        => $this->correlationId,
            'posted_at'             => now()->subMinutes(10),
            'created_by'            => (string) Uuid::uuid7(),
            'created_at'            => now()->subMinutes(10),
        ]);

        // Balanced: 10000 debit, 10000 credit
        DB::connection('platform')->table('ledger.journal_lines')->insert([
            ['id' => (string) Uuid::uuid7(), 'journal_id' => $journalId, 'ledger_account_id' => $accountId,       'direction' => 'debit',  'amount' => 10000, 'currency_code' => 'BWP', 'created_at' => now()],
            ['id' => (string) Uuid::uuid7(), 'journal_id' => $journalId, 'ledger_account_id' => $creditAccountId, 'direction' => 'credit', 'amount' => 10000, 'currency_code' => 'BWP', 'created_at' => now()],
        ]);

        return $journalId;
    }

    // ── Test 1: Balanced ledger → clean snapshot ───────────────────────────────

    public function test_balanced_ledger_produces_clean_snapshot(): void
    {
        $this->insertBalancedJournal();

        dispatch_sync(new LedgerIntegrityJob(correlationId: $this->correlationId));

        $snapshot = LedgerIntegritySnapshotModel::query()
            ->orderByDesc('scan_at')
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertSame('clean', $snapshot->outcome);
        $this->assertSame(0, $snapshot->discrepancy_count);
        $this->assertEmpty($snapshot->affected_wallet_ids);
    }

    // ── Test 2: Imbalanced journal → discrepancy_found + audit event ───────────

    public function test_imbalanced_journal_produces_discrepancy_found_snapshot(): void
    {
        $journalId = (string) Uuid::uuid7();
        $accountId = (string) Uuid::uuid7();

        DB::connection('platform')->table('ledger.ledger_accounts')->insertOrIgnore([
            'id'                       => $accountId,
            'ledger_account_reference' => 'test-imbalanced-' . $accountId,
            'account_type'             => 'asset',
            'normal_balance'           => 'debit',
            'currency_code'            => 'BWP',
            'owner_reference'          => (string) Uuid::uuid7(),
            'owner_type'               => 'wallet',
            'status'                   => 'active',
            'created_by'               => (string) Uuid::uuid7(),
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        DB::connection('platform')->table('ledger.journals')->insert([
            'id'              => $journalId,
            'idempotency_key' => (string) Uuid::uuid7(),
            'entry_type'      => 'debit',
            'currency_code'   => 'BWP',
            'status'          => 'posted',
            'correlation_id'  => $this->correlationId,
            'posted_at'       => now()->subMinutes(10),
            'created_by'      => (string) Uuid::uuid7(),
            'created_at'      => now()->subMinutes(10),
        ]);

        // Deliberately imbalanced: 10000 debit, only 9000 credit
        DB::connection('platform')->table('ledger.journal_lines')->insert([
            ['id' => (string) Uuid::uuid7(), 'journal_id' => $journalId, 'ledger_account_id' => $accountId, 'direction' => 'debit',  'amount' => 10000, 'currency_code' => 'BWP', 'created_at' => now()],
            ['id' => (string) Uuid::uuid7(), 'journal_id' => $journalId, 'ledger_account_id' => $accountId, 'direction' => 'credit', 'amount' => 9000,  'currency_code' => 'BWP', 'created_at' => now()],
        ]);

        dispatch_sync(new LedgerIntegrityJob(correlationId: $this->correlationId));

        $snapshot = LedgerIntegritySnapshotModel::query()
            ->orderByDesc('scan_at')
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertSame('discrepancy_found', $snapshot->outcome);
        $this->assertGreaterThan(0, $snapshot->discrepancy_count);

        // Audit event emitted for the imbalanced wallet
        $this->assertDatabaseHas('platform.audit_events', [
            'action'       => 'platform.ledger.integrity_failure_detected',
            'resource_type' => 'wallet',
        ]);
    }

    // ── Test 3: In-flight journals (posted < 60s ago) excluded ─────────────────

    public function test_in_flight_journals_excluded_from_scan(): void
    {
        $journalId = (string) Uuid::uuid7();
        $accountId = (string) Uuid::uuid7();

        DB::connection('platform')->table('ledger.ledger_accounts')->insertOrIgnore([
            'id'                       => $accountId,
            'ledger_account_reference' => 'test-inflight-' . $accountId,
            'account_type'             => 'asset',
            'normal_balance'           => 'debit',
            'currency_code'            => 'BWP',
            'owner_reference'          => (string) Uuid::uuid7(),
            'owner_type'               => 'wallet',
            'status'                   => 'active',
            'created_by'               => (string) Uuid::uuid7(),
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        DB::connection('platform')->table('ledger.journals')->insert([
            'id'              => $journalId,
            'idempotency_key' => (string) Uuid::uuid7(),
            'entry_type'      => 'debit',
            'currency_code'   => 'BWP',
            'status'          => 'posted',
            'correlation_id'  => $this->correlationId,
            'posted_at'       => now()->subSeconds(10), // within 60s window
            'created_by'      => (string) Uuid::uuid7(),
            'created_at'      => now()->subSeconds(10),
        ]);

        // Imbalanced but posted only 10 seconds ago — should be excluded
        DB::connection('platform')->table('ledger.journal_lines')->insert([
            ['id' => (string) Uuid::uuid7(), 'journal_id' => $journalId, 'ledger_account_id' => $accountId, 'direction' => 'debit',  'amount' => 5000, 'currency_code' => 'BWP', 'created_at' => now()],
            ['id' => (string) Uuid::uuid7(), 'journal_id' => $journalId, 'ledger_account_id' => $accountId, 'direction' => 'credit', 'amount' => 3000, 'currency_code' => 'BWP', 'created_at' => now()],
        ]);

        dispatch_sync(new LedgerIntegrityJob(correlationId: $this->correlationId));

        $snapshot = LedgerIntegritySnapshotModel::query()
            ->orderByDesc('scan_at')
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertSame('clean', $snapshot->outcome, 'In-flight imbalanced journals must be excluded from the scan');
    }
}
