<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum PaymentAttemptStatus: string
{
    // P06: new states
    case Submitting   = 'submitting';
    case Unknown      = 'unknown';

    // Pre-P06: kept for backward compatibility with existing DB rows
    case Initiated      = 'initiated';
    case Processing     = 'processing';
    case AwaitingAction = 'awaiting_action';
    case Succeeded      = 'succeeded';
    case Failed         = 'failed';
    case Cancelled      = 'cancelled';
    case Expired        = 'expired';
    case TimedOut       = 'timed_out';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded,
            self::Failed,
            self::Cancelled,
            self::Expired => true,
            // Unknown and TimedOut are NOT terminal — outcome is unresolved, hold preserved
            default       => false,
        };
    }

    /** @return array<self> */
    private static function transitions(): array
    {
        return [
            self::Submitting->value     => [self::Processing, self::AwaitingAction, self::Succeeded, self::Failed, self::Unknown],
            self::Initiated->value      => [self::Processing, self::AwaitingAction, self::Succeeded, self::Failed, self::Cancelled, self::Expired, self::Unknown],
            self::Processing->value     => [self::Succeeded, self::Failed, self::TimedOut, self::Unknown],
            self::AwaitingAction->value => [self::Processing, self::Cancelled, self::Expired, self::TimedOut, self::Unknown],
            self::Unknown->value        => [self::Succeeded, self::Failed, self::Expired],
            self::TimedOut->value       => [self::Succeeded, self::Failed, self::Expired],
            self::Succeeded->value      => [],
            self::Failed->value         => [],
            self::Cancelled->value      => [],
            self::Expired->value        => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions()[$this->value] ?? [], strict: true);
    }
}
