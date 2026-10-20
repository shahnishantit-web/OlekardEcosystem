<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Domain\Exceptions;

use RuntimeException;

final class WithdrawalLimitViolationException extends RuntimeException
{
    private function __construct(
        public readonly string $violationCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function belowMinimum(int $amount, int $min): self
    {
        return new self(
            'AMOUNT_BELOW_MINIMUM',
            "Amount {$amount} is below the minimum {$min}.",
            ['minimum' => $min, 'amount' => $amount],
        );
    }

    public static function aboveMaximum(int $amount, int $max): self
    {
        return new self(
            'AMOUNT_ABOVE_MAXIMUM',
            "Amount {$amount} exceeds the maximum {$max}.",
            ['maximum' => $max, 'amount' => $amount],
        );
    }

    public static function dailyLimitExceeded(int $amount, int $dailyMax, int $dailyUsed): self
    {
        return new self(
            'DAILY_LIMIT_EXCEEDED',
            "Daily limit of {$dailyMax} would be exceeded.",
            ['daily_max' => $dailyMax, 'daily_used' => $dailyUsed, 'requested' => $amount, 'daily_remaining' => max(0, $dailyMax - $dailyUsed)],
        );
    }
}
