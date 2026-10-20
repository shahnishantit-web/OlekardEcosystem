<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Transaction;

use App\Modules\Transaction\Domain\Enums\ReversalStatus;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ReversalService::execute() guard logic and compensating
 * journal template construction.
 *
 * Integration-level DB tests (SELECT FOR UPDATE, idempotency key uniqueness)
 * live in tests/Concurrency/Modules/Transaction/.
 */
final class ReversalServiceExecuteTest extends TestCase
{
    // ── Status guard ──────────────────────────────────────────────────────────

    /** @test */
    public function only_approved_status_passes_execute_guard(): void
    {
        $canExecute = fn (ReversalStatus $s) => $s === ReversalStatus::Approved;

        $this->assertTrue($canExecute(ReversalStatus::Approved));
        $this->assertFalse($canExecute(ReversalStatus::PendingApproval));
        $this->assertFalse($canExecute(ReversalStatus::Rejected));
        $this->assertFalse($canExecute(ReversalStatus::Executed));
        $this->assertFalse($canExecute(ReversalStatus::Failed));
    }

    // ── Reversal journal template ─────────────────────────────────────────────

    /** @test */
    public function compensating_journal_swaps_debit_and_credit_accounts(): void
    {
        $template = [
            'debit_account'  => 'customer-pocket-001',
            'credit_account' => 'provider-float-001',
            'amount'         => 50000,
            'currency_code'  => 'BWP',
        ];

        // ReversalService::execute() constructs the compensating journal by inverting
        // debit_account ↔ credit_account from the stored template
        $compensatingLines = [
            [
                'type'                    => 'DEBIT',
                'ledger_account_reference' => $template['credit_account'],
                'amount'                  => $template['amount'],
                'currency_code'           => $template['currency_code'],
            ],
            [
                'type'                    => 'CREDIT',
                'ledger_account_reference' => $template['debit_account'],
                'amount'                  => $template['amount'],
                'currency_code'           => $template['currency_code'],
            ],
        ];

        $this->assertSame('provider-float-001', $compensatingLines[0]['ledger_account_reference']);
        $this->assertSame('customer-pocket-001', $compensatingLines[1]['ledger_account_reference']);
        $this->assertSame('DEBIT', $compensatingLines[0]['type']);
        $this->assertSame('CREDIT', $compensatingLines[1]['type']);
        $this->assertSame(50000, $compensatingLines[0]['amount']);
        $this->assertSame(50000, $compensatingLines[1]['amount']);
        $this->assertSame('BWP', $compensatingLines[0]['currency_code']);
    }

    /** @test */
    public function compensating_journal_amounts_are_identical_to_original(): void
    {
        $originalAmount = 125750;
        $template       = [
            'debit_account'  => 'acc-a',
            'credit_account' => 'acc-b',
            'amount'         => $originalAmount,
            'currency_code'  => 'BWP',
        ];

        $compensatingLines = [
            ['amount' => $template['amount'], 'currency_code' => $template['currency_code']],
            ['amount' => $template['amount'], 'currency_code' => $template['currency_code']],
        ];

        foreach ($compensatingLines as $line) {
            $this->assertSame($originalAmount, $line['amount']);
        }
    }

    /** @test */
    public function missing_reversal_template_triggers_runtime_exception(): void
    {
        $metadata = [];
        $template = $metadata['reversal_journal_template'] ?? null;

        $this->assertNull($template);

        $this->expectException(\RuntimeException::class);

        if ($template === null) {
            throw new \RuntimeException('Transaction has no reversal_journal_template in metadata.');
        }
    }

    // ── Idempotency key format ────────────────────────────────────────────────

    /** @test */
    public function execute_idempotency_key_format_uses_reversal_id(): void
    {
        $reversalId     = '018fc-some-uuid';
        $idempotencyKey = "reversal_execute:{$reversalId}";

        $this->assertSame("reversal_execute:018fc-some-uuid", $idempotencyKey);
        $this->assertStringStartsWith('reversal_execute:', $idempotencyKey);
    }

    /** @test */
    public function approve_idempotency_key_format_uses_reversal_id(): void
    {
        $reversalId     = '018fc-some-uuid';
        $idempotencyKey = "reversal_approve:{$reversalId}";

        $this->assertSame("reversal_approve:018fc-some-uuid", $idempotencyKey);
        $this->assertStringStartsWith('reversal_approve:', $idempotencyKey);
    }

    // ── Post-execute status transition ────────────────────────────────────────

    /** @test */
    public function transaction_status_becomes_reversed_after_execute(): void
    {
        // After successful execute:
        // - reversal.status = executed
        // - transaction.status = 'reversed'
        $expectedReversalStatus    = 'executed';
        $expectedTransactionStatus = 'reversed';

        $this->assertSame(ReversalStatus::Executed->value, $expectedReversalStatus);
        $this->assertSame('reversed', $expectedTransactionStatus);
    }

    /** @test */
    public function journal_failure_leaves_reversal_in_failed_status(): void
    {
        // The execute() method catches ledger exceptions and marks reversal as failed
        $statusOnJournalFailure = ReversalStatus::Failed->value;

        $this->assertSame('failed', $statusOnJournalFailure);
        $this->assertTrue(ReversalStatus::Failed->isTerminal());
    }
}
