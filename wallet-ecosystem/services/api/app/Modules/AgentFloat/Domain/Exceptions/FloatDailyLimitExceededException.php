<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Domain\Exceptions;

final class FloatDailyLimitExceededException extends \DomainException
{
    public function __construct(
        public readonly int $dailyUsed,
        public readonly int $dailyLimit,
        public readonly string $errorCode = 'DAILY_LIMIT_EXCEEDED',
    ) {
        parent::__construct("Daily volume limit exceeded: used {$dailyUsed}, limit {$dailyLimit}.");
    }

    public static function exceeded(int $dailyUsed, int $dailyLimit): self
    {
        return new self($dailyUsed, $dailyLimit);
    }
}
