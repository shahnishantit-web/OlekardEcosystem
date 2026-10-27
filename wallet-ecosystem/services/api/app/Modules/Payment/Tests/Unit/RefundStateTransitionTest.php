<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use PHPUnit\Framework\TestCase;

/**
 * T013 — Complete refund state machine validation:
 *   - All valid transitions allowed
 *   - All invalid transitions rejected via canTransitionTo()
 *   - Unknown reservation not released until explicit terminal transition
 *   - DefinitiveFailure releases reservation (terminal)
 */
final class RefundStateTransitionTest extends TestCase
{
    // ─── Valid transitions ────────────────────────────────────────────────────

    public function test_requested_can_transition_to_pending_approval(): void
    {
        $this->assertTrue(PaymentRefundStatus::Requested->canTransitionTo(PaymentRefundStatus::PendingApproval));
    }

    public function test_requested_can_transition_to_processing(): void
    {
        $this->assertTrue(PaymentRefundStatus::Requested->canTransitionTo(PaymentRefundStatus::Processing));
    }

    public function test_pending_approval_can_transition_to_processing(): void
    {
        $this->assertTrue(PaymentRefundStatus::PendingApproval->canTransitionTo(PaymentRefundStatus::Processing));
    }

    public function test_pending_approval_can_transition_to_rejected(): void
    {
        $this->assertTrue(PaymentRefundStatus::PendingApproval->canTransitionTo(PaymentRefundStatus::Rejected));
    }

    public function test_pending_approval_can_transition_to_cancelled(): void
    {
        $this->assertTrue(PaymentRefundStatus::PendingApproval->canTransitionTo(PaymentRefundStatus::Cancelled));
    }

    public function test_processing_can_transition_to_submitted(): void
    {
        $this->assertTrue(PaymentRefundStatus::Processing->canTransitionTo(PaymentRefundStatus::Submitted));
    }

    public function test_processing_can_transition_to_confirmed(): void
    {
        $this->assertTrue(PaymentRefundStatus::Processing->canTransitionTo(PaymentRefundStatus::Confirmed));
    }

    public function test_processing_can_transition_to_failed(): void
    {
        $this->assertTrue(PaymentRefundStatus::Processing->canTransitionTo(PaymentRefundStatus::Failed));
    }

    public function test_processing_can_transition_to_cancelled(): void
    {
        $this->assertTrue(PaymentRefundStatus::Processing->canTransitionTo(PaymentRefundStatus::Cancelled));
    }

    public function test_submitted_can_transition_to_pending(): void
    {
        $this->assertTrue(PaymentRefundStatus::Submitted->canTransitionTo(PaymentRefundStatus::Pending));
    }

    public function test_submitted_can_transition_to_confirmed(): void
    {
        $this->assertTrue(PaymentRefundStatus::Submitted->canTransitionTo(PaymentRefundStatus::Confirmed));
    }

    public function test_submitted_can_transition_to_unknown(): void
    {
        $this->assertTrue(PaymentRefundStatus::Submitted->canTransitionTo(PaymentRefundStatus::Unknown));
    }

    public function test_submitted_can_transition_to_definitive_failure(): void
    {
        $this->assertTrue(PaymentRefundStatus::Submitted->canTransitionTo(PaymentRefundStatus::DefinitiveFailure));
    }

    public function test_pending_can_transition_to_confirmed(): void
    {
        $this->assertTrue(PaymentRefundStatus::Pending->canTransitionTo(PaymentRefundStatus::Confirmed));
    }

    public function test_pending_can_transition_to_definitive_failure(): void
    {
        $this->assertTrue(PaymentRefundStatus::Pending->canTransitionTo(PaymentRefundStatus::DefinitiveFailure));
    }

    public function test_pending_can_transition_to_unknown(): void
    {
        $this->assertTrue(PaymentRefundStatus::Pending->canTransitionTo(PaymentRefundStatus::Unknown));
    }

    public function test_unknown_can_transition_to_confirmed(): void
    {
        $this->assertTrue(PaymentRefundStatus::Unknown->canTransitionTo(PaymentRefundStatus::Confirmed));
    }

    public function test_unknown_can_transition_to_definitive_failure(): void
    {
        $this->assertTrue(PaymentRefundStatus::Unknown->canTransitionTo(PaymentRefundStatus::DefinitiveFailure));
    }

    // ─── Invalid transitions (terminal → anything) ────────────────────────────

    public function test_confirmed_cannot_transition_anywhere(): void
    {
        foreach (PaymentRefundStatus::cases() as $target) {
            $this->assertFalse(
                PaymentRefundStatus::Confirmed->canTransitionTo($target),
                "Confirmed should not transition to {$target->value}"
            );
        }
    }

    public function test_definitive_failure_cannot_transition_anywhere(): void
    {
        foreach (PaymentRefundStatus::cases() as $target) {
            $this->assertFalse(
                PaymentRefundStatus::DefinitiveFailure->canTransitionTo($target),
                "DefinitiveFailure should not transition to {$target->value}"
            );
        }
    }

    public function test_succeeded_cannot_transition_anywhere(): void
    {
        foreach (PaymentRefundStatus::cases() as $target) {
            $this->assertFalse(
                PaymentRefundStatus::Succeeded->canTransitionTo($target),
                "Succeeded should not transition to {$target->value}"
            );
        }
    }

    public function test_failed_cannot_transition_anywhere(): void
    {
        foreach (PaymentRefundStatus::cases() as $target) {
            $this->assertFalse(
                PaymentRefundStatus::Failed->canTransitionTo($target),
                "Failed should not transition to {$target->value}"
            );
        }
    }

    public function test_cancelled_cannot_transition_anywhere(): void
    {
        foreach (PaymentRefundStatus::cases() as $target) {
            $this->assertFalse(
                PaymentRefundStatus::Cancelled->canTransitionTo($target),
                "Cancelled should not transition to {$target->value}"
            );
        }
    }

    public function test_rejected_cannot_transition_anywhere(): void
    {
        foreach (PaymentRefundStatus::cases() as $target) {
            $this->assertFalse(
                PaymentRefundStatus::Rejected->canTransitionTo($target),
                "Rejected should not transition to {$target->value}"
            );
        }
    }

    // ─── Invalid non-terminal transitions ────────────────────────────────────

    public function test_requested_cannot_transition_to_confirmed(): void
    {
        $this->assertFalse(PaymentRefundStatus::Requested->canTransitionTo(PaymentRefundStatus::Confirmed));
    }

    public function test_processing_cannot_transition_to_pending(): void
    {
        $this->assertFalse(PaymentRefundStatus::Processing->canTransitionTo(PaymentRefundStatus::Pending));
    }

    public function test_processing_cannot_transition_to_unknown(): void
    {
        $this->assertFalse(PaymentRefundStatus::Processing->canTransitionTo(PaymentRefundStatus::Unknown));
    }

    public function test_processing_cannot_transition_to_definitive_failure(): void
    {
        $this->assertFalse(PaymentRefundStatus::Processing->canTransitionTo(PaymentRefundStatus::DefinitiveFailure));
    }

    public function test_submitted_cannot_skip_to_failed(): void
    {
        $this->assertFalse(PaymentRefundStatus::Submitted->canTransitionTo(PaymentRefundStatus::Failed));
    }

    public function test_unknown_cannot_transition_to_pending(): void
    {
        $this->assertFalse(PaymentRefundStatus::Unknown->canTransitionTo(PaymentRefundStatus::Pending));
    }

    public function test_unknown_cannot_transition_to_failed(): void
    {
        $this->assertFalse(PaymentRefundStatus::Unknown->canTransitionTo(PaymentRefundStatus::Failed));
    }

    // ─── Reservation semantics for Unknown ───────────────────────────────────

    public function test_unknown_status_is_non_terminal(): void
    {
        $this->assertFalse(PaymentRefundStatus::Unknown->isTerminal());
    }

    public function test_unknown_status_reserves_funds(): void
    {
        $this->assertTrue(PaymentRefundStatus::Unknown->reservesFunds());
    }

    public function test_definitive_failure_releases_reservation(): void
    {
        $this->assertFalse(PaymentRefundStatus::DefinitiveFailure->reservesFunds());
        $this->assertTrue(PaymentRefundStatus::DefinitiveFailure->isTerminal());
    }

    // ─── New P15 states are terminal as expected ──────────────────────────────

    public function test_confirmed_is_terminal(): void
    {
        $this->assertTrue(PaymentRefundStatus::Confirmed->isTerminal());
    }

    public function test_definitive_failure_is_terminal(): void
    {
        $this->assertTrue(PaymentRefundStatus::DefinitiveFailure->isTerminal());
    }

    // ─── consumerLabel correctness ────────────────────────────────────────────

    public function test_unknown_consumer_label_is_under_review(): void
    {
        $this->assertSame('Under review', PaymentRefundStatus::Unknown->consumerLabel());
    }

    public function test_confirmed_consumer_label_is_funds_returned(): void
    {
        $this->assertSame('Funds returned', PaymentRefundStatus::Confirmed->consumerLabel());
    }

    public function test_definitive_failure_consumer_label_is_refund_declined(): void
    {
        $this->assertSame('Refund declined', PaymentRefundStatus::DefinitiveFailure->consumerLabel());
    }

    public function test_pending_consumer_label_is_being_processed(): void
    {
        $this->assertSame('Being processed', PaymentRefundStatus::Pending->consumerLabel());
    }
}
