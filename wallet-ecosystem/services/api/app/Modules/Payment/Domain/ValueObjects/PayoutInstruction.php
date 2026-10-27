<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

/** Server-approved references only. Does not create an executable payout API. */
final readonly class PayoutInstruction
{
    public function __construct(
        public string $operationId,
        public string $providerAccountId,
        public string $approvedBeneficiaryReference,
        public int $amountMinor,
        public string $currencyCode,
    ) {
        if ($operationId === '' || $providerAccountId === '' || $approvedBeneficiaryReference === ''
            || $amountMinor <= 0 || ! preg_match('/^[A-Z]{3}$/D', $currencyCode)) {
            throw new \InvalidArgumentException('Invalid server-approved payout instruction.');
        }
    }
}
