<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payment\StateMachines;

use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Exceptions\InvalidStateTransitionException;
use App\Modules\Payment\Domain\StateMachines\PaymentAttemptStateMachine;
use PHPUnit\Framework\TestCase;

final class PaymentAttemptStateMachineTest extends TestCase
{
    // ── Valid transitions ────────────────────────────────────────────────

    /** @test */
    public function initiated_to_processing_is_valid(): void
    {
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::Initiated,
            PaymentAttemptStatus::Processing,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function initiated_to_awaiting_action_is_valid(): void
    {
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::Initiated,
            PaymentAttemptStatus::AwaitingAction,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function initiated_to_succeeded_is_valid(): void
    {
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::Initiated,
            PaymentAttemptStatus::Succeeded,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function initiated_to_failed_is_valid(): void
    {
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::Initiated,
            PaymentAttemptStatus::Failed,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function initiated_to_cancelled_is_valid(): void
    {
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::Initiated,
            PaymentAttemptStatus::Cancelled,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function initiated_to_expired_is_valid(): void
    {
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::Initiated,
            PaymentAttemptStatus::Expired,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function processing_to_succeeded_is_valid(): void
    {
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::Processing,
            PaymentAttemptStatus::Succeeded,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function processing_to_failed_is_valid(): void
    {
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::Processing,
            PaymentAttemptStatus::Failed,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function processing_to_timed_out_is_valid(): void
    {
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::Processing,
            PaymentAttemptStatus::TimedOut,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function awaiting_action_to_processing_is_valid(): void
    {
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::AwaitingAction,
            PaymentAttemptStatus::Processing,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function awaiting_action_to_cancelled_is_valid(): void
    {
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::AwaitingAction,
            PaymentAttemptStatus::Cancelled,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function awaiting_action_to_expired_is_valid(): void
    {
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::AwaitingAction,
            PaymentAttemptStatus::Expired,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function timed_out_to_succeeded_is_valid(): void
    {
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::TimedOut,
            PaymentAttemptStatus::Succeeded,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function timed_out_to_failed_is_valid(): void
    {
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::TimedOut,
            PaymentAttemptStatus::Failed,
        );
        $this->assertTrue(true);
    }

    /** @test */
    public function timed_out_to_expired_is_valid(): void
    {
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::TimedOut,
            PaymentAttemptStatus::Expired,
        );
        $this->assertTrue(true);
    }

    // ── Invalid transitions ──────────────────────────────────────────────

    /** @test */
    public function succeeded_to_processing_is_invalid(): void
    {
        $this->expectException(InvalidStateTransitionException::class);
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::Succeeded,
            PaymentAttemptStatus::Processing,
        );
    }

    /** @test */
    public function failed_to_initiated_is_invalid(): void
    {
        $this->expectException(InvalidStateTransitionException::class);
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::Failed,
            PaymentAttemptStatus::Initiated,
        );
    }

    /** @test */
    public function cancelled_to_processing_is_invalid(): void
    {
        $this->expectException(InvalidStateTransitionException::class);
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::Cancelled,
            PaymentAttemptStatus::Processing,
        );
    }

    /** @test */
    public function expired_to_succeeded_is_invalid(): void
    {
        $this->expectException(InvalidStateTransitionException::class);
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::Expired,
            PaymentAttemptStatus::Succeeded,
        );
    }

    /** @test */
    public function processing_to_initiated_is_invalid(): void
    {
        $this->expectException(InvalidStateTransitionException::class);
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::Processing,
            PaymentAttemptStatus::Initiated,
        );
    }

    // ── isTerminal() ─────────────────────────────────────────────────────

    /** @test */
    public function succeeded_is_terminal(): void
    {
        $this->assertTrue(PaymentAttemptStateMachine::isTerminal(PaymentAttemptStatus::Succeeded));
    }

    /** @test */
    public function failed_is_terminal(): void
    {
        $this->assertTrue(PaymentAttemptStateMachine::isTerminal(PaymentAttemptStatus::Failed));
    }

    /** @test */
    public function cancelled_is_terminal(): void
    {
        $this->assertTrue(PaymentAttemptStateMachine::isTerminal(PaymentAttemptStatus::Cancelled));
    }

    /** @test */
    public function expired_is_terminal(): void
    {
        $this->assertTrue(PaymentAttemptStateMachine::isTerminal(PaymentAttemptStatus::Expired));
    }

    /** @test */
    public function initiated_is_not_terminal(): void
    {
        $this->assertFalse(PaymentAttemptStateMachine::isTerminal(PaymentAttemptStatus::Initiated));
    }

    /** @test */
    public function processing_is_not_terminal(): void
    {
        $this->assertFalse(PaymentAttemptStateMachine::isTerminal(PaymentAttemptStatus::Processing));
    }

    /** @test */
    public function timed_out_is_not_terminal(): void
    {
        $this->assertFalse(PaymentAttemptStateMachine::isTerminal(PaymentAttemptStatus::TimedOut));
    }
}
