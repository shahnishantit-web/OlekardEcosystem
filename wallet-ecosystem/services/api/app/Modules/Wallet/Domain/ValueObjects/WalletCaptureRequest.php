<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\ValueObjects;

/** Internal Payment-to-Wallet request; the hold remains the amount/currency authority. */
final readonly class WalletCaptureRequest
{
    public function __construct(
        public string $holdId,
        public string $paymentAttemptId,
        public string $paymentIntentId,
        public string $organisationId,
        public string $ownerActorId,
        public int $expectedAmountMinor,
        public string $expectedCurrencyCode,
        public string $correlationId,
    ) {}
}
