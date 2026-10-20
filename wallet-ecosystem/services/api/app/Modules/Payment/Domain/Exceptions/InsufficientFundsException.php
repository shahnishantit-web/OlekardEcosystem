<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

final class InsufficientFundsException extends \RuntimeException
{
    public function __construct(
        private readonly int $availableMinor,
        private readonly int $requiredMinor,
        private readonly string $currencyCode
    ) {
        parent::__construct(
            "Insufficient wallet funds: required {$requiredMinor} {$currencyCode}, "
            . "available {$availableMinor} {$currencyCode}."
        );
    }

    public function getAvailableMinor(): int
    {
        return $this->availableMinor;
    }

    public function getRequiredMinor(): int
    {
        return $this->requiredMinor;
    }

    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }
}
