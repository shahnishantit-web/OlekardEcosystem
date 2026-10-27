<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

final readonly class PaymentHistoryRecord
{
    public function __construct(
        public string      $paymentReference,
        public string      $purposeType,
        public string      $purposeReference,
        public string|array $domainDeepLink,
        public ?string     $providerLabel,
        public int         $originalAmountMinor,
        public string      $currencyCode,
        public int         $feeAmountMinor,
        public int         $totalChargedMinor,
        public string      $paymentStatus,
        public string      $refundStatus,
        public ?int        $refundAmountMinor,
        public bool        $refundEligible,
        public string      $paymentDate,
        public string      $organisationId,
    ) {
    }
}
