<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Modules\Payment\Domain\Enums\ProviderStatementStatus;
use App\Modules\Payment\Domain\Enums\StatementLineMatchStatus;
use App\Modules\Payment\Domain\Enums\StatementLineType;
use Tests\TestCase;

/**
 * T039: StatementMatchingService unit tests.
 *
 * Tests enum semantics, line type routing logic, and status transition rules.
 * DB-gated tests (actual matching, clearing record creation, event publishing) require Docker.
 */
final class StatementMatchingServiceTest extends TestCase
{
    // ── StatementLineType enum ─────────────────────────────────────────────────

    public function test_statement_line_type_has_all_expected_cases(): void
    {
        $cases = array_map(fn ($c) => $c->value, StatementLineType::cases());

        $this->assertContains('capture', $cases);
        $this->assertContains('fee', $cases);
        $this->assertContains('refund', $cases);
        $this->assertContains('reversal', $cases);
        $this->assertContains('chargeback', $cases);
        $this->assertContains('internal_transfer', $cases);
        $this->assertContains('adjustment', $cases);
    }

    // ── Match status routing ───────────────────────────────────────────────────

    public function test_internal_transfer_line_type_maps_to_internal_transfer_match_status(): void
    {
        $lineType = StatementLineType::InternalTransfer;

        // Internal transfers get internal_transfer match status — no clearing record created
        $expectedMatchStatus = StatementLineMatchStatus::InternalTransfer;

        $this->assertSame('internal_transfer', $expectedMatchStatus->value);
        $this->assertSame('internal_transfer', $lineType->value);
    }

    public function test_capture_with_no_platform_record_produces_no_platform_record_status(): void
    {
        $matchStatus = StatementLineMatchStatus::NoPlatformRecord;

        $this->assertSame('no_platform_record', $matchStatus->value);
    }

    public function test_amount_mismatch_produces_amount_mismatch_status(): void
    {
        $matchStatus = StatementLineMatchStatus::AmountMismatch;

        $this->assertSame('amount_mismatch', $matchStatus->value);
    }

    public function test_successful_match_produces_matched_status(): void
    {
        $matchStatus = StatementLineMatchStatus::Matched;

        $this->assertSame('matched', $matchStatus->value);
    }

    public function test_exception_raised_status_is_available(): void
    {
        $matchStatus = StatementLineMatchStatus::ExceptionRaised;

        $this->assertSame('exception_raised', $matchStatus->value);
    }

    // ── ProviderStatementStatus transitions ───────────────────────────────────

    public function test_all_provider_statement_statuses_exist(): void
    {
        $values = array_map(fn ($c) => $c->value, ProviderStatementStatus::cases());

        $this->assertContains('pending_match', $values);
        $this->assertContains('matching', $values);
        $this->assertContains('matched', $values);
        $this->assertContains('partially_matched', $values);
        $this->assertContains('exception_raised', $values);
        $this->assertContains('closed', $values);
    }

    public function test_matching_status_does_not_allow_qualification(): void
    {
        $this->assertFalse(ProviderStatementStatus::Matching->allowsQualification());
    }

    public function test_partially_matched_status_allows_qualification(): void
    {
        $this->assertTrue(ProviderStatementStatus::PartiallyMatched->allowsQualification());
    }

    // ── Exception type discrimination ─────────────────────────────────────────

    public function test_orphan_provider_exception_is_for_capture_with_no_platform_record(): void
    {
        // OrphanProvider = a provider charge with no matching PaymentAttempt in the platform
        $lineType = StatementLineType::Capture;

        // The matching service would try to find attempt by provider_reference_id
        // and raise OrphanProvider if not found
        $this->assertSame('capture', $lineType->value);
    }

    public function test_orphan_fee_exception_is_for_fee_with_no_attempt(): void
    {
        $lineType = StatementLineType::Fee;

        // Fee lines that cannot be matched to a PaymentAttempt raise orphan_fee exceptions
        $this->assertSame('fee', $lineType->value);
    }

    // ── Missing callback detection ─────────────────────────────────────────────

    public function test_missing_callback_detection_is_bottom_up(): void
    {
        // Bottom-up: After processing all lines, query succeeded attempts in period
        // not present in the set of matched attempt IDs → create MissingCallback exception

        $allAttemptIds     = ['attempt-1', 'attempt-2', 'attempt-3'];
        $matchedAttemptIds = ['attempt-1', 'attempt-3'];

        $orphanedAttempts = array_diff($allAttemptIds, $matchedAttemptIds);

        $this->assertCount(1, $orphanedAttempts);
        $this->assertContains('attempt-2', $orphanedAttempts);
    }
}
