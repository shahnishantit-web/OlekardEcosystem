<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

final readonly class PaymentRefundRequest
{
    public function __construct(
        public string $paymentIntentId,
        public string $paymentAttemptId,
        public string $refundId,
        public int    $amountMinor,
        public string $currencyCode,
        public string $correlationId,
        public string $idempotencyKey,
        public ?string $providerReferenceId = null,
    ) {
    }
}
