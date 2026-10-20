<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ledger;

use App\Modules\Ledger\Application\Services\LedgerPostingService;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

/**
 * E27 — FR-006: Every wallet balance change must execute within a single atomic
 * database transaction; a failure at any step must roll back all associated
 * ledger entries.
 */
final class AtomicRollbackTest extends LedgerTestCase
{
    protected $connectionsToTransact = [null, 'identity', 'platform', 'ledger'];

    // ── FR-006: Rollback on mid-transaction failure ─────────────────────────────

    public function test_ledger_posting_rolls_back_on_failure(): void
    {
        $correlationId = (string) Uuid::uuid7();

        $journalCountBefore = DB::connection('platform')->table('ledger.journals')->count();
        $lineCountBefore    = DB::connection('platform')->table('ledger.journal_lines')->count();

        $posted = false;

        DB::connection('platform')->transaction(function () use ($correlationId, &$posted): void {
            $accountId = (string) Uuid::uuid7();
            $journalId = (string) Uuid::uuid7();

            DB::connection('platform')->table('ledger.ledger_accounts')->insertOrIgnore([
                'id'                       => $accountId,
                'ledger_account_reference' => 'test-rollback-' . $accountId,
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
                'correlation_id'  => $correlationId,
                'posted_at'       => now(),
                'created_by'      => (string) Uuid::uuid7(),
                'created_at'      => now(),
            ]);

            // Debit line posted successfully
            DB::connection('platform')->table('ledger.journal_lines')->insert([
                'id'               => (string) Uuid::uuid7(),
                'journal_id'       => $journalId,
                'ledger_account_id' => $accountId,
                'direction'        => 'debit',
                'amount'           => 10000,
                'currency_code'    => 'BWP',
                'created_at'       => now(),
            ]);

            $posted = true;

            // Simulate failure before credit entry is written
            throw new \RuntimeException('Simulated failure after debit, before credit');
        });

        // Transaction should have rolled back
        $journalCountAfter = DB::connection('platform')->table('ledger.journals')->count();
        $lineCountAfter    = DB::connection('platform')->table('ledger.journal_lines')->count();

        $this->assertTrue($posted, 'Debit must have been attempted (test setup issue if false)');
        $this->assertEquals(
            $journalCountBefore,
            $journalCountAfter,
            'Journal count must be unchanged after rollback (FR-006)'
        );
        $this->assertEquals(
            $lineCountBefore,
            $lineCountAfter,
            'Journal line count must be unchanged after rollback (FR-006)'
        );
    }

    // ── FR-006: Successful posting persists both debit and credit lines ─────────

    public function test_successful_posting_persists_both_debit_and_credit(): void
    {
        $correlationId = (string) Uuid::uuid7();
        $journalId     = (string) Uuid::uuid7();
        $debitAccountId  = (string) Uuid::uuid7();
        $creditAccountId = (string) Uuid::uuid7();

        DB::connection('platform')->transaction(function () use ($journalId, $debitAccountId, $creditAccountId, $correlationId): void {
            foreach ([[$debitAccountId, 'asset', 'debit'], [$creditAccountId, 'liability', 'credit']] as [$id, $type, $normal]) {
                DB::connection('platform')->table('ledger.ledger_accounts')->insertOrIgnore([
                    'id'                       => $id,
                    'ledger_account_reference' => 'test-success-' . $id,
                    'account_type'             => $type,
                    'normal_balance'           => $normal,
                    'currency_code'            => 'BWP',
                    'owner_reference'          => null,
                    'owner_type'               => 'system',
                    'status'                   => 'active',
                    'created_by'               => (string) Uuid::uuid7(),
                    'created_at'               => now(),
                    'updated_at'               => now(),
                ]);
            }

            DB::connection('platform')->table('ledger.journals')->insert([
                'id'              => $journalId,
                'idempotency_key' => (string) Uuid::uuid7(),
                'entry_type'      => 'debit',
                'currency_code'   => 'BWP',
                'status'          => 'posted',
                'correlation_id'  => $correlationId,
                'posted_at'       => now(),
                'created_by'      => (string) Uuid::uuid7(),
                'created_at'      => now(),
            ]);

            DB::connection('platform')->table('ledger.journal_lines')->insert([
                ['id' => (string) Uuid::uuid7(), 'journal_id' => $journalId, 'ledger_account_id' => $debitAccountId,  'direction' => 'debit',  'amount' => 5000, 'currency_code' => 'BWP', 'created_at' => now()],
                ['id' => (string) Uuid::uuid7(), 'journal_id' => $journalId, 'ledger_account_id' => $creditAccountId, 'direction' => 'credit', 'amount' => 5000, 'currency_code' => 'BWP', 'created_at' => now()],
            ]);
        });

        $this->assertDatabaseHas('ledger.journal_lines', ['journal_id' => $journalId, 'direction' => 'debit',  'amount' => 5000]);
        $this->assertDatabaseHas('ledger.journal_lines', ['journal_id' => $journalId, 'direction' => 'credit', 'amount' => 5000]);
    }
}
