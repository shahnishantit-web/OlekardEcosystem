<?php

declare(strict_types=1);

namespace App\Modules\Funding\Domain\Exceptions;

final class FundingLimitExceededException extends \RuntimeException
{
    public function __construct(
        public readonly string $limitType,
        public readonly string $errorCode,
        public readonly array $context = [],
    ) {
        parent::__construct("Funding limit exceeded: {$limitType}");
    }

    public static function minimumNotMet(int $amount, int $minimum): self
    {
        return new self('minimum', 'FUNDING_BELOW_MINIMUM', [
            'amount'  => $amount,
            'minimum' => $minimum,
        ]);
    }

    public static function transactionMaxExceeded(int $amount, int $max): self
    {
        return new self('per_transaction_max', 'FUNDING_EXCEEDS_TX_LIMIT', [
            'amount' => $amount,
            'max'    => $max,
        ]);
    }

    public static function dailyLimitExceeded(int $amount, int $dailyMax, int $dailySpent): self
    {
        return new self('daily_max', 'FUNDING_DAILY_LIMIT_EXCEEDED', [
            'amount'      => $amount,
            'daily_max'   => $dailyMax,
            'daily_spent' => $dailySpent,
        ]);
    }
}
