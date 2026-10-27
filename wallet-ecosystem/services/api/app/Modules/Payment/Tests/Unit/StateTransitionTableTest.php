<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Exceptions\InvalidStateTransitionException;
use App\Modules\Payment\Domain\StateMachines\PaymentAttemptStateMachine;
use PHPUnit\Framework\TestCase;

/**
 * T024 (P06 US3): Complete state transition table validation.
 *
 * Enumerates all PaymentAttemptStatus × PaymentAttemptStatus pairs and verifies
 * each matches the authoritative transition table from data-model.md.
 *
 * Key P06 assertions:
 * - Unknown.isTerminal() === false  (hold preserved until reconciled)
 * - TimedOut.isTerminal() === false (not yet retired from P06 but also not terminal)
 * - Submitting transitions to processing/awaiting_action/succeeded/failed/unknown
 * - Unknown transitions to succeeded/failed/expired only
 */
final class StateTransitionTableTest extends TestCase
{
    /**
     * Authoritative transition table (from → to → expected).
     * Derived from PaymentAttemptStateMachine::ALLOWED_TRANSITIONS.
     *
     * @return array<string, array{from: string, to: string, expected: bool}>
     */
    public static function transitionMatrix(): array
    {
        $cases = [];

        // Expected transitions per P06 data-model.md
        $allowed = [
            'submitting'      => ['processing', 'awaiting_action', 'succeeded', 'failed', 'unknown'],
            'unknown'         => ['succeeded', 'failed', 'expired'],
            'initiated'       => ['processing', 'awaiting_action', 'succeeded', 'failed', 'cancelled', 'expired', 'unknown'],
            'processing'      => ['succeeded', 'failed', 'timed_out', 'unknown'],
            'awaiting_action' => ['processing', 'succeeded', 'failed', 'cancelled', 'expired', 'timed_out', 'unknown'],
            'timed_out'       => ['succeeded', 'failed', 'expired'],
            'succeeded'       => [],
            'failed'          => [],
            'cancelled'       => [],
            'expired'         => [],
        ];

        $allStatuses = array_map(fn($c) => $c->value, PaymentAttemptStatus::cases());

        foreach ($allowed as $from => $targets) {
            foreach ($allStatuses as $to) {
                $cases["{$from}→{$to}"] = [
                    'from'     => $from,
                    'to'       => $to,
                    'expected' => in_array($to, $targets, strict: true),
                ];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider transitionMatrix
     */
    public function test_state_machine_matches_authoritative_table(
        string $from,
        string $to,
        bool $expected,
    ): void {
        $fromStatus = PaymentAttemptStatus::from($from);
        $toStatus   = PaymentAttemptStatus::from($to);

        if ($expected) {
            // Should NOT throw
            PaymentAttemptStateMachine::assertValidTransition($fromStatus, $toStatus);
            $this->assertTrue(true); // assertion reached
        } else {
            $this->expectException(InvalidStateTransitionException::class);
            PaymentAttemptStateMachine::assertValidTransition($fromStatus, $toStatus);
        }
    }

    // ─── P06-specific invariant assertions ───────────────────────────────────

    public function test_unknown_is_not_terminal(): void
    {
        $this->assertFalse(
            PaymentAttemptStatus::Unknown->isTerminal(),
            'Unknown must not be terminal — wallet hold must be preserved until reconciled'
        );
    }

    public function test_timed_out_is_not_terminal(): void
    {
        $this->assertFalse(
            PaymentAttemptStatus::TimedOut->isTerminal(),
            'TimedOut must not be terminal — it is an intermediate state pending reconciliation'
        );
    }

    public function test_succeeded_is_terminal(): void
    {
        $this->assertTrue(PaymentAttemptStatus::Succeeded->isTerminal());
    }

    public function test_failed_is_terminal(): void
    {
        $this->assertTrue(PaymentAttemptStatus::Failed->isTerminal());
    }

    public function test_cancelled_is_terminal(): void
    {
        $this->assertTrue(PaymentAttemptStatus::Cancelled->isTerminal());
    }

    public function test_expired_is_terminal(): void
    {
        $this->assertTrue(PaymentAttemptStatus::Expired->isTerminal());
    }

    public function test_submitting_is_not_terminal(): void
    {
        $this->assertFalse(
            PaymentAttemptStatus::Submitting->isTerminal(),
            'Submitting is in-flight — must not be treated as resolved'
        );
    }

    /** Verifies that unknown → succeeded transition is allowed (resolve via callback). */
    public function test_unknown_can_transition_to_succeeded(): void
    {
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::Unknown,
            PaymentAttemptStatus::Succeeded,
        );
        $this->assertTrue(true);
    }

    /** Verifies that unknown → failed transition is allowed (confirmed failure callback). */
    public function test_unknown_can_transition_to_failed(): void
    {
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::Unknown,
            PaymentAttemptStatus::Failed,
        );
        $this->assertTrue(true);
    }

    /** Verifies submitting → unknown is allowed (stuck SUBMITTING detected by timeout). */
    public function test_submitting_can_transition_to_unknown(): void
    {
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::Submitting,
            PaymentAttemptStatus::Unknown,
        );
        $this->assertTrue(true);
    }

    /** Verifies processing → unknown is allowed (ambiguous timeout). */
    public function test_processing_can_transition_to_unknown(): void
    {
        PaymentAttemptStateMachine::assertValidTransition(
            PaymentAttemptStatus::Processing,
            PaymentAttemptStatus::Unknown,
        );
        $this->assertTrue(true);
    }
}
