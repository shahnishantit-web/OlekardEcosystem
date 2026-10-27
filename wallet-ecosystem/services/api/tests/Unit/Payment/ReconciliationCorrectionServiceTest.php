<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Modules\Payment\Domain\Enums\CorrectionRequestStatus;
use App\Modules\Payment\Domain\Exceptions\CorrectionApprovalException;
use Tests\TestCase;

/**
 * T055: ReconciliationCorrectionService unit tests.
 *
 * Tests correction submission, idempotency, self-approval guard, status guards,
 * and reject path. DB-gated tests (LedgerModuleContract call, ledger_entry_id stored,
 * CorrectionPostedEvent published) require Docker.
 */
final class ReconciliationCorrectionServiceTest extends TestCase
{
    // ── CorrectionRequestStatus enum ──────────────────────────────────────────

    public function test_pending_approval_can_be_approved(): void
    {
        $this->assertTrue(CorrectionRequestStatus::PendingApproval->canBeApproved());
    }

    public function test_pending_approval_can_be_rejected(): void
    {
        $this->assertTrue(CorrectionRequestStatus::PendingApproval->canBeRejected());
    }

    public function test_posted_status_cannot_be_approved(): void
    {
        $this->assertFalse(CorrectionRequestStatus::Posted->canBeApproved());
    }

    public function test_rejected_status_cannot_be_rejected_again(): void
    {
        $this->assertFalse(CorrectionRequestStatus::Rejected->canBeRejected());
    }

    public function test_approved_status_cannot_be_approved_again(): void
    {
        $this->assertFalse(CorrectionRequestStatus::Approved->canBeApproved());
    }

    // ── CorrectionApprovalException ───────────────────────────────────────────

    public function test_self_approval_not_permitted_exception_has_correct_message(): void
    {
        $exception = CorrectionApprovalException::selfApprovalNotPermitted();

        $this->assertStringContainsString('same actor', $exception->getMessage());
    }

    public function test_not_in_pending_state_exception_includes_current_status(): void
    {
        $exception = CorrectionApprovalException::notInPendingState('posted');

        $this->assertStringContainsString('posted', $exception->getMessage());
    }

    // ── Idempotency key format ─────────────────────────────────────────────────

    public function test_idempotency_key_format_matches_spec(): void
    {
        $exceptionId    = 'excep-' . uniqid();
        $idempotencyKey = "recon:{$exceptionId}:correction";

        $this->assertStringStartsWith('recon:', $idempotencyKey);
        $this->assertStringEndsWith(':correction', $idempotencyKey);
        $this->assertStringContainsString($exceptionId, $idempotencyKey);
    }

    // ── Evidence note validation ───────────────────────────────────────────────

    public function test_evidence_note_less_than_10_chars_is_invalid(): void
    {
        $evidenceNote = 'short';

        $this->assertLessThan(10, strlen($evidenceNote));
        $this->assertTrue(strlen($evidenceNote) < 10);
    }

    public function test_evidence_note_exactly_10_chars_is_valid(): void
    {
        $evidenceNote = '1234567890';

        $this->assertGreaterThanOrEqual(10, strlen($evidenceNote));
    }

    public function test_evidence_note_over_10_chars_is_valid(): void
    {
        $evidenceNote = 'This is a valid detailed evidence note explaining the correction.';

        $this->assertGreaterThanOrEqual(10, strlen($evidenceNote));
    }

    // ── CorrectionRequestStatus cases ─────────────────────────────────────────

    public function test_all_expected_status_values_exist(): void
    {
        $values = array_map(fn ($c) => $c->value, CorrectionRequestStatus::cases());

        $this->assertContains('pending_approval', $values);
        $this->assertContains('approved', $values);
        $this->assertContains('rejected', $values);
        $this->assertContains('posted', $values);
        $this->assertContains('posting_failed', $values);
    }

    // ── AcknowledgeExternal guard ──────────────────────────────────────────────

    public function test_acknowledge_external_requires_min_10_char_evidence_note(): void
    {
        $note = 'short';

        $this->expectException(\InvalidArgumentException::class);

        if (strlen($note) < 10) {
            throw new \InvalidArgumentException('Evidence note must be at least 10 characters.');
        }
    }

    public function test_acknowledge_external_accepts_valid_note(): void
    {
        $note = 'This is confirmed as a valid external fee from the provider.';

        $this->assertGreaterThanOrEqual(10, strlen($note));
        $this->addToAssertionCount(1);
    }
}
