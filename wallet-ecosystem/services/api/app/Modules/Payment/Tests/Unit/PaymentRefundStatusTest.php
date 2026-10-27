<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use PHPUnit\Framework\TestCase;

final class PaymentRefundStatusTest extends TestCase
{
    public function test_pending_approval_is_not_terminal(): void
    {
        $this->assertFalse(PaymentRefundStatus::PendingApproval->isTerminal());
    }

    public function test_rejected_is_terminal(): void
    {
        $this->assertTrue(PaymentRefundStatus::Rejected->isTerminal());
    }

    public function test_succeeded_is_terminal(): void
    {
        $this->assertTrue(PaymentRefundStatus::Succeeded->isTerminal());
    }

    public function test_failed_is_terminal(): void
    {
        $this->assertTrue(PaymentRefundStatus::Failed->isTerminal());
    }

    public function test_cancelled_is_terminal(): void
    {
        $this->assertTrue(PaymentRefundStatus::Cancelled->isTerminal());
    }

    public function test_processing_is_not_terminal(): void
    {
        $this->assertFalse(PaymentRefundStatus::Processing->isTerminal());
    }

    public function test_pending_approval_can_transition_to_processing(): void
    {
        $this->assertTrue(
            PaymentRefundStatus::PendingApproval->canTransitionTo(PaymentRefundStatus::Processing)
        );
    }

    public function test_pending_approval_can_transition_to_rejected(): void
    {
        $this->assertTrue(
            PaymentRefundStatus::PendingApproval->canTransitionTo(PaymentRefundStatus::Rejected)
        );
    }

    public function test_pending_approval_can_transition_to_cancelled(): void
    {
        $this->assertTrue(
            PaymentRefundStatus::PendingApproval->canTransitionTo(PaymentRefundStatus::Cancelled)
        );
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

    public function test_succeeded_cannot_transition_anywhere(): void
    {
        foreach (PaymentRefundStatus::cases() as $target) {
            $this->assertFalse(
                PaymentRefundStatus::Succeeded->canTransitionTo($target),
                "Succeeded should not transition to {$target->value}"
            );
        }
    }

    public function test_processing_can_transition_to_succeeded(): void
    {
        $this->assertTrue(
            PaymentRefundStatus::Processing->canTransitionTo(PaymentRefundStatus::Succeeded)
        );
    }

    public function test_processing_can_transition_to_failed(): void
    {
        $this->assertTrue(
            PaymentRefundStatus::Processing->canTransitionTo(PaymentRefundStatus::Failed)
        );
    }
}
