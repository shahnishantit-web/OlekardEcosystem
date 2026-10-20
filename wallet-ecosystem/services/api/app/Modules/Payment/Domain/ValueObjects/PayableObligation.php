<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

final readonly class PayableObligation
{
    public function __construct(
        public int    $amountMinor,
        public string $currencyCode,
        public string $organisationId,
        public string $payerActorId,
        public array  $metadata = [],
    ) {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException(
                "PayableObligation amount_minor must be a positive integer, got [{$amountMinor}]."
            );
        }
    }
}
