<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Domain\Exceptions;

final class InsufficientBalanceException extends \RuntimeException
{
    public function __construct(
        public readonly int $grossAmount,
        public readonly int $availableBalance,
    ) {
        parent::__construct(
            "Insufficient balance: required {$grossAmount}, available {$availableBalance}."
        );
    }

    public static function forAmount(int $gross, int $balance): self
    {
        return new self($gross, $balance);
    }
}
