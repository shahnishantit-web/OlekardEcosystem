<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

final class TransactionLimitExceededException extends \DomainException
{
    public function __construct(
        public readonly int    $amountMinor,
        public readonly int    $limitMinor,
        public readonly string $currencyCode,
    ) {
        parent::__construct(
            "Payment amount {$amountMinor} {$currencyCode} exceeds per-transaction limit of {$limitMinor} {$currencyCode}."
        );
    }
}
