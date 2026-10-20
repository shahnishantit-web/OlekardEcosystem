<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ledger;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;
use Tests\Support\AssertOutboxEvent;

/**
 * T077 — US-LEDGER-013: ledger:check-balance Artisan command.
 * Clean ledger → exit 0. Orphan debit → imbalance detected, event emitted, exit non-zero.
 */
final class LedgerBalanceCheckCommandTest extends LedgerTestCase
{
    use AssertOutboxEvent;

    private object $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdminWithPermission('ledger.post');
    }

    private function postBalancedEntry(int $amount = 1000): void
    {
        $dr = 'cmd-dr-' . substr((string) Uuid::uuid7(), 0, 8);
        $cr = 'cmd-cr-' . substr((string) Uuid::uuid7(), 0, 8);

        $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => $dr, 'direction' => 'debit',  'amount' => $amount],
                    ['ledger_account_reference' => $cr, 'direction' => 'credit', 'amount' => $amount],
                ],
            ])
            ->assertStatus(201);
    }

    // Scenario (a): clean ledger → exits 0, no Ledger.Imbalance.Detected event.
    public function test_clean_ledger_exits_zero(): void
    {
        $this->postBalancedEntry(5000);
        $this->postBalancedEntry(3000);

        $this->artisan('ledger:check-balance')
             ->assertSuccessful();
    }

    // Scenario (b): orphan debit line → imbalance detected, event emitted, exits non-zero.
    public function test_orphan_debit_line_triggers_imbalance_detection(): void
    {
        // Insert a balanced journal so there is at least one posted journal
        $this->postBalancedEntry(1000);

        // Manually insert an orphan debit journal_line not balanced by a credit.
        // First we need a real journal and a real ledger_account to satisfy FK constraints.
        $orphanAccountId = (string) Uuid::uuid7();
        DB::connection('platform')->table('ledger.ledger_accounts')->insert([
            'id'                       => $orphanAccountId,
            'ledger_account_reference' => 'orphan-acct-' . substr((string) Uuid::uuid7(), 0, 8),
            'account_type'             => 'asset',
            'normal_balance'           => 'debit',
            'currency_code'            => 'KES',
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
            'idempotency_key'       => 'orphan-key-' . (string) Uuid::uuid7(),
            'entry_type'            => 'debit',
            'status'                => 'posted',
            'currency_code'         => 'KES',
            'correlation_id'        => (string) Uuid::uuid7(),
            'transaction_reference' => null,
            'reversed_journal_id'   => null,
            'posted_by'             => $this->admin->id,
            'posted_at'             => now(),
            'created_at'            => now(),
        ]);

        // Insert an unmatched debit line — no corresponding credit.
        DB::connection('platform')->table('ledger.journal_lines')->insert([
            'id'               => (string) Uuid::uuid7(),
            'journal_id'       => $orphanJournalId,
            'ledger_account_id' => $orphanAccountId,
            'direction'        => 'debit',
            'amount'           => 9999,
            'currency_code'    => 'KES',
            'created_at'       => now(),
        ]);

        $this->artisan('ledger:check-balance')
             ->assertFailed();

        $this->assertOutboxEventPublished('Ledger.Imbalance.Detected', [
            'currency_code' => 'KES',
        ]);
    }

    // Scenario (c): run command twice → same result, idempotent (no extra events for clean ledger).
    public function test_command_is_idempotent_on_clean_ledger(): void
    {
        $this->postBalancedEntry(2000);

        $this->artisan('ledger:check-balance')->assertSuccessful();
        $this->artisan('ledger:check-balance')->assertSuccessful();
    }

    // Scenario (d): no auto-correction applied after imbalance detected.
    public function test_no_auto_correction_after_imbalance(): void
    {
        // Insert orphan debit line (same approach as scenario b)
        $orphanAccountId = (string) Uuid::uuid7();
        DB::connection('platform')->table('ledger.ledger_accounts')->insert([
            'id'                       => $orphanAccountId,
            'ledger_account_reference' => 'no-fix-acct-' . substr((string) Uuid::uuid7(), 0, 8),
            'account_type'             => 'asset',
            'normal_balance'           => 'debit',
            'currency_code'            => 'USD',
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
            'idempotency_key'       => 'no-fix-key-' . (string) Uuid::uuid7(),
            'entry_type'            => 'debit',
            'status'                => 'posted',
            'currency_code'         => 'USD',
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
            'amount'           => 500,
            'currency_code'    => 'USD',
            'created_at'       => now(),
        ]);

        $lineBefore = DB::connection('platform')
            ->table('ledger.journal_lines')
            ->where('journal_id', $orphanJournalId)
            ->count();

        $this->artisan('ledger:check-balance')->assertFailed();

        // Lines must remain unchanged — no auto-correction
        $lineAfter = DB::connection('platform')
            ->table('ledger.journal_lines')
            ->where('journal_id', $orphanJournalId)
            ->count();

        $this->assertSame($lineBefore, $lineAfter);
    }
}
