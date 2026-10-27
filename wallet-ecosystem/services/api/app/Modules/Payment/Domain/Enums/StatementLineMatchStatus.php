<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum StatementLineMatchStatus: string
{
    case Pending          = 'pending';
    case Matched          = 'matched';
    case AmountMismatch   = 'amount_mismatch';
    case NoPlatformRecord = 'no_platform_record';
    case InternalTransfer = 'internal_transfer';
    case ExceptionRaised  = 'exception_raised';

    public function isResolved(): bool
    {
        return match ($this) {
            self::Matched, self::InternalTransfer => true,
            default                               => false,
        };
    }

    public function requiresException(): bool
    {
        return match ($this) {
            self::AmountMismatch, self::NoPlatformRecord, self::ExceptionRaised => true,
            default => false,
        };
    }
}
