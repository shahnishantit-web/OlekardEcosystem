<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\StateMachines;

use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use App\Modules\Payment\Domain\Exceptions\InvalidStateTransitionException;

final class PaymentIntentStateMachine
{
    /** @var array<string, string[]> */
    private const ALLOWED_TRANSITIONS = [
        'pending'               => ['processing', 'cancelled', 'expired'],
        'processing'            => ['awaiting_action', 'requires_confirmation', 'succeeded', 'failed', 'cancelled'],
        'awaiting_action'       => ['requires_confirmation', 'processing', 'succeeded', 'failed', 'cancelled', 'expired'],
        'requires_confirmation' => ['succeeded', 'failed'],
        'succeeded'             => ['refunding'],
        'refunding'             => ['partially_refunded', 'refunded'],
        'partially_refunded'    => ['refunding', 'refunded'],
        'failed'                => [],
        'cancelled'             => [],
        'expired'               => [],
        'refunded'              => [],
    ];

    /**
     * @throws InvalidStateTransitionException
     */
    public static function assertValidTransition(
        PaymentIntentStatus $current,
        PaymentIntentStatus $target,
    ): void {
        $allowed = self::ALLOWED_TRANSITIONS[$current->value] ?? [];
        if (!in_array($target->value, $allowed, strict: true)) {
            throw new InvalidStateTransitionException('PaymentIntent', $current->value, $target->value);
        }
    }

    public static function isTerminal(PaymentIntentStatus $status): bool
    {
        return $status->isTerminal();
    }

    /** @return PaymentIntentStatus[] */
    public static function allowedTargets(PaymentIntentStatus $from): array
    {
        return array_map(
            static fn (string $v) => PaymentIntentStatus::from($v),
            self::ALLOWED_TRANSITIONS[$from->value] ?? [],
        );
    }
}
