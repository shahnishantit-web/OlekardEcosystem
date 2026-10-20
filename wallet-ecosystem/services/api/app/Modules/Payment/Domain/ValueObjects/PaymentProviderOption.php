<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

final readonly class PaymentProviderOption
{
    public function __construct(
        public string  $providerCode,
        public string  $displayName,
        public bool    $isWallet,
        public bool    $isDefault,
        public int     $sortOrder,
        public ?int    $walletBalanceMinor = null,
        public ?bool   $hasSufficientBalance = null,
        public ?string $checkoutFlow = null,
    ) {
    }
}
