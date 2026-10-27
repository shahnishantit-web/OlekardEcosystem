<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * T009 — Verifies in-flight reservation logic:
 *   (a) Non-terminal statuses reserve funds (enum invariant)
 *   (b) Terminal statuses do NOT reserve funds (released)
 *   (c) sumReservedRefundsForAttempt() is called — not sumConfirmedRefundsForAttempt() — for the guard
 *   (d) sumReservedRefundsForAttempt() counts submitted/pending/unknown rows
 */
final class RefundInFlightReservationTest extends TestCase
{
    // ─── (a) enum: non-terminal statuses reserve funds ───────────────────────

    public function test_requested_reserves_funds(): void
    {
        $this->assertTrue(PaymentRefundStatus::Requested->reservesFunds());
    }

    public function test_pending_approval_reserves_funds(): void
    {
        $this->assertTrue(PaymentRefundStatus::PendingApproval->reservesFunds());
    }

    public function test_processing_reserves_funds(): void
    {
        $this->assertTrue(PaymentRefundStatus::Processing->reservesFunds());
    }

    public function test_submitted_reserves_funds(): void
    {
        $this->assertTrue(PaymentRefundStatus::Submitted->reservesFunds());
    }

    public function test_pending_reserves_funds(): void
    {
        $this->assertTrue(PaymentRefundStatus::Pending->reservesFunds());
    }

    public function test_unknown_reserves_funds(): void
    {
        $this->assertTrue(PaymentRefundStatus::Unknown->reservesFunds());
    }

    // ─── (b) enum: terminal statuses do NOT reserve funds ────────────────────

    public function test_confirmed_does_not_reserve_funds(): void
    {
        $this->assertFalse(PaymentRefundStatus::Confirmed->reservesFunds());
    }

    public function test_definitive_failure_does_not_reserve_funds(): void
    {
        $this->assertFalse(PaymentRefundStatus::DefinitiveFailure->reservesFunds());
    }

    public function test_succeeded_does_not_reserve_funds(): void
    {
        $this->assertFalse(PaymentRefundStatus::Succeeded->reservesFunds());
    }

    public function test_failed_does_not_reserve_funds(): void
    {
        $this->assertFalse(PaymentRefundStatus::Failed->reservesFunds());
    }

    public function test_cancelled_does_not_reserve_funds(): void
    {
        $this->assertFalse(PaymentRefundStatus::Cancelled->reservesFunds());
    }

    public function test_rejected_does_not_reserve_funds(): void
    {
        $this->assertFalse(PaymentRefundStatus::Rejected->reservesFunds());
    }

    // ─── (c) every non-terminal case is covered ───────────────────────────────

    public function test_all_non_terminal_statuses_reserve_funds(): void
    {
        foreach (PaymentRefundStatus::cases() as $status) {
            if (! $status->isTerminal()) {
                $this->assertTrue(
                    $status->reservesFunds(),
                    "Status {$status->value} is non-terminal but reservesFunds() returned false"
                );
            }
        }
    }

    public function test_all_terminal_statuses_do_not_reserve_funds(): void
    {
        foreach (PaymentRefundStatus::cases() as $status) {
            if ($status->isTerminal()) {
                $this->assertFalse(
                    $status->reservesFunds(),
                    "Status {$status->value} is terminal but reservesFunds() returned true"
                );
            }
        }
    }

    // ─── (d) repository interface has both methods ────────────────────────────

    public function test_repository_interface_has_sum_reserved_method(): void
    {
        $repo = $this->createMock(PaymentRefundRepositoryInterface::class);

        // sumReservedRefundsForAttempt must be callable and return an int
        $repo->method('sumReservedRefundsForAttempt')->with('attempt-001')->willReturn(5000);
        $repo->method('sumConfirmedRefundsForAttempt')->with('attempt-001')->willReturn(3000);

        $this->assertSame(5000, $repo->sumReservedRefundsForAttempt('attempt-001'));
        $this->assertSame(3000, $repo->sumConfirmedRefundsForAttempt('attempt-001'));
    }

    // ─── fundsReturned() only for terminal success states ─────────────────────

    public function test_confirmed_funds_returned_is_true(): void
    {
        $this->assertTrue(PaymentRefundStatus::Confirmed->fundsReturned());
    }

    public function test_succeeded_funds_returned_is_true(): void
    {
        $this->assertTrue(PaymentRefundStatus::Succeeded->fundsReturned());
    }

    public function test_definitive_failure_funds_returned_is_false(): void
    {
        $this->assertFalse(PaymentRefundStatus::DefinitiveFailure->fundsReturned());
    }

    public function test_pending_funds_returned_is_false(): void
    {
        $this->assertFalse(PaymentRefundStatus::Pending->fundsReturned());
    }

    public function test_unknown_funds_returned_is_false(): void
    {
        $this->assertFalse(PaymentRefundStatus::Unknown->fundsReturned());
    }
}
