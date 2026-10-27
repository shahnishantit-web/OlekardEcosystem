<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

final readonly class PaymentProviderOption
{
    /**
     * @param string[]             $eligibleInstrumentTypes
     * @param array<string, mixed> $capabilityFlags
     * @param array<string, mixed>|null $feeDisclosure  Fee breakdown: {service_fee_minor, provider_fee_minor, total_fee_minor, total_payable_minor}
     * @param array<string>|null        $restrictions   Availability restrictions (e.g. country codes, purpose types)
     */
    public function __construct(
        public string  $providerCode,
        public string  $displayName,
        public bool    $isWallet,
        public bool    $isDefault,
        public int     $sortOrder,
        public ?int    $walletBalanceMinor = null,
        public ?bool   $hasSufficientBalance = null,
        public ?string $checkoutFlow = null,
        public ?string $displayDescription = null,
        public ?string $displayIconKey = null,
        public ?string $displayBadge = null,
        public array   $eligibleInstrumentTypes = [],
        public array   $capabilityFlags = [],
        // T073: P12 dynamic bank integration display fields
        public ?string $displayLabel = null,
        public ?string $paymentMethod = null,
        public int     $displayOrder = 0,
        public ?array  $feeDisclosure = null,
        public ?array  $restrictions = null,
        public ?string $supportedNextAction = null,
    ) {
    }
}
