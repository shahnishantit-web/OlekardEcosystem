<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Domain\Enums\PaymentAllocationStatus;
use PHPUnit\Framework\TestCase;

final class PaymentAllocationStatusTest extends TestCase
{
    public function test_allocated_is_not_terminal(): void
    {
        $this->assertFalse(PaymentAllocationStatus::Allocated->isTerminal());
    }

    public function test_settled_is_terminal(): void
    {
        $this->assertTrue(PaymentAllocationStatus::Settled->isTerminal());
    }

    public function test_refunded_is_terminal(): void
    {
        $this->assertTrue(PaymentAllocationStatus::Refunded->isTerminal());
    }

    public function test_cancelled_is_terminal(): void
    {
        $this->assertTrue(PaymentAllocationStatus::Cancelled->isTerminal());
    }

    public function test_allocated_can_transition_to_settled(): void
    {
        $this->assertTrue(
            PaymentAllocationStatus::Allocated->canTransitionTo(PaymentAllocationStatus::Settled)
        );
    }

    public function test_allocated_can_transition_to_refunded(): void
    {
        $this->assertTrue(
            PaymentAllocationStatus::Allocated->canTransitionTo(PaymentAllocationStatus::Refunded)
        );
    }

    public function test_allocated_can_transition_to_cancelled(): void
    {
        $this->assertTrue(
            PaymentAllocationStatus::Allocated->canTransitionTo(PaymentAllocationStatus::Cancelled)
        );
    }

    public function test_settled_cannot_transition_back_to_allocated(): void
    {
        $this->assertFalse(
            PaymentAllocationStatus::Settled->canTransitionTo(PaymentAllocationStatus::Allocated)
        );
    }

    public function test_refunded_cannot_transition_to_any_state(): void
    {
        $this->assertFalse(
            PaymentAllocationStatus::Refunded->canTransitionTo(PaymentAllocationStatus::Allocated)
        );
        $this->assertFalse(
            PaymentAllocationStatus::Refunded->canTransitionTo(PaymentAllocationStatus::Settled)
        );
    }
}
