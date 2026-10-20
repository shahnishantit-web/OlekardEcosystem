<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payment\StateMachines;

use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use App\Modules\Payment\Domain\Exceptions\InvalidStateTransitionException;
use App\Modules\Payment\Domain\StateMachines\PaymentIntentStateMachine;
use PHPUnit\Framework\TestCase;

final class PaymentIntentStateMachineTest extends TestCase
{
    // ── Valid transitions ────────────────────────────────────────────────

    /** @test */
    public function pending_to_processing_is_valid(): void
    {
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::Pending,
            PaymentIntentStatus::Processing,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function pending_to_cancelled_is_valid(): void
    {
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::Pending,
            PaymentIntentStatus::Cancelled,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function pending_to_expired_is_valid(): void
    {
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::Pending,
            PaymentIntentStatus::Expired,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function processing_to_awaiting_action_is_valid(): void
    {
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::Processing,
            PaymentIntentStatus::AwaitingAction,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function processing_to_requires_confirmation_is_valid(): void
    {
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::Processing,
            PaymentIntentStatus::RequiresConfirmation,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function processing_to_succeeded_is_valid(): void
    {
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::Processing,
            PaymentIntentStatus::Succeeded,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function processing_to_failed_is_valid(): void
    {
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::Processing,
            PaymentIntentStatus::Failed,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function processing_to_cancelled_is_valid(): void
    {
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::Processing,
            PaymentIntentStatus::Cancelled,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function awaiting_action_to_requires_confirmation_is_valid(): void
    {
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::AwaitingAction,
            PaymentIntentStatus::RequiresConfirmation,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function awaiting_action_to_processing_is_valid(): void
    {
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::AwaitingAction,
            PaymentIntentStatus::Processing,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function awaiting_action_to_cancelled_is_valid(): void
    {
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::AwaitingAction,
            PaymentIntentStatus::Cancelled,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function awaiting_action_to_expired_is_valid(): void
    {
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::AwaitingAction,
            PaymentIntentStatus::Expired,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function requires_confirmation_to_succeeded_is_valid(): void
    {
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::RequiresConfirmation,
            PaymentIntentStatus::Succeeded,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function requires_confirmation_to_failed_is_valid(): void
    {
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::RequiresConfirmation,
            PaymentIntentStatus::Failed,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function succeeded_to_refunding_is_valid(): void
    {
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::Succeeded,
            PaymentIntentStatus::Refunding,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function refunding_to_partially_refunded_is_valid(): void
    {
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::Refunding,
            PaymentIntentStatus::PartiallyRefunded,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function refunding_to_refunded_is_valid(): void
    {
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::Refunding,
            PaymentIntentStatus::Refunded,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function partially_refunded_to_refunding_is_valid(): void
    {
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::PartiallyRefunded,
            PaymentIntentStatus::Refunding,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function partially_refunded_to_refunded_is_valid(): void
    {
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::PartiallyRefunded,
            PaymentIntentStatus::Refunded,
        );
        $this->assertTrue(true);
    }

    // ── Invalid transitions ──────────────────────────────────────────────

    /** @test */
    public function failed_to_processing_is_invalid(): void
    {
        $this->expectException(InvalidStateTransitionException::class);
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::Failed,
            PaymentIntentStatus::Processing,
        );
    }

    /** @test */
    public function succeeded_to_processing_is_invalid(): void
    {
        $this->expectException(InvalidStateTransitionException::class);
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::Succeeded,
            PaymentIntentStatus::Processing,
        );
    }

    /** @test */
    public function cancelled_to_pending_is_invalid(): void
    {
        $this->expectException(InvalidStateTransitionException::class);
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::Cancelled,
            PaymentIntentStatus::Pending,
        );
    }

    /** @test */
    public function expired_to_processing_is_invalid(): void
    {
        $this->expectException(InvalidStateTransitionException::class);
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::Expired,
            PaymentIntentStatus::Processing,
        );
    }

    /** @test */
    public function refunded_to_refunding_is_invalid(): void
    {
        $this->expectException(InvalidStateTransitionException::class);
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::Refunded,
            PaymentIntentStatus::Refunding,
        );
    }

    /** @test */
    public function pending_to_succeeded_is_invalid(): void
    {
        $this->expectException(InvalidStateTransitionException::class);
        PaymentIntentStateMachine::assertValidTransition(
            PaymentIntentStatus::Pending,
            PaymentIntentStatus::Succeeded,
        );
    }

    // ── isTerminal() ─────────────────────────────────────────────────────

    /** @test */
    public function succeeded_is_terminal(): void
    {
        $this->assertTrue(PaymentIntentStateMachine::isTerminal(PaymentIntentStatus::Succeeded));
    }

    /** @test */
    public function failed_is_terminal(): void
    {
        $this->assertTrue(PaymentIntentStateMachine::isTerminal(PaymentIntentStatus::Failed));
    }

    /** @test */
    public function cancelled_is_terminal(): void
    {
        $this->assertTrue(PaymentIntentStateMachine::isTerminal(PaymentIntentStatus::Cancelled));
    }

    /** @test */
    public function expired_is_terminal(): void
    {
        $this->assertTrue(PaymentIntentStateMachine::isTerminal(PaymentIntentStatus::Expired));
    }

    /** @test */
    public function refunded_is_terminal(): void
    {
        $this->assertTrue(PaymentIntentStateMachine::isTerminal(PaymentIntentStatus::Refunded));
    }

    /** @test */
    public function pending_is_not_terminal(): void
    {
        $this->assertFalse(PaymentIntentStateMachine::isTerminal(PaymentIntentStatus::Pending));
    }

    /** @test */
    public function processing_is_not_terminal(): void
    {
        $this->assertFalse(PaymentIntentStateMachine::isTerminal(PaymentIntentStatus::Processing));
    }

    /** @test */
    public function refunding_is_not_terminal(): void
    {
        $this->assertFalse(PaymentIntentStateMachine::isTerminal(PaymentIntentStatus::Refunding));
    }

    /** @test */
    public function partially_refunded_is_not_terminal(): void
    {
        $this->assertFalse(PaymentIntentStateMachine::isTerminal(PaymentIntentStatus::PartiallyRefunded));
    }
}
