<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\StateMachines;

use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Exceptions\InvalidStateTransitionException;

final class PaymentAttemptStateMachine
{
    /** @var array<string, string[]> */
    private const ALLOWED_TRANSITIONS = [
        // P06 new states
        'submitting'      => ['processing', 'awaiting_action', 'succeeded', 'failed', 'unknown'],
        'unknown'         => ['succeeded', 'failed', 'expired'],
        // Legacy states (kept for backward compat with existing DB rows)
        'initiated'       => ['processing', 'awaiting_action', 'succeeded', 'failed', 'cancelled', 'expired', 'unknown'],
        'processing'      => ['succeeded', 'failed', 'timed_out', 'unknown'],
        'awaiting_action' => ['processing', 'succeeded', 'failed', 'cancelled', 'expired', 'timed_out', 'unknown'],
        'timed_out'       => ['succeeded', 'failed', 'expired'],
        'succeeded'       => [],
        'failed'          => [],
        'cancelled'       => [],
        'expired'         => [],
    ];

    /**
     * @throws InvalidStateTransitionException
     */
    public static function assertValidTransition(
        PaymentAttemptStatus $current,
        PaymentAttemptStatus $target,
    ): void {
        $allowed = self::ALLOWED_TRANSITIONS[$current->value] ?? [];
        if (!in_array($target->value, $allowed, strict: true)) {
            throw new InvalidStateTransitionException('PaymentAttempt', $current->value, $target->value);
        }
    }

    public static function isTerminal(PaymentAttemptStatus $status): bool
    {
        return $status->isTerminal();
    }

    /** @return PaymentAttemptStatus[] */
    public static function allowedTargets(PaymentAttemptStatus $from): array
    {
        return array_map(
            static fn (string $v) => PaymentAttemptStatus::from($v),
            self::ALLOWED_TRANSITIONS[$from->value] ?? [],
        );
    }
}
