<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

final readonly class PaymentInitiateRequest
{
    public function __construct(
        public string  $paymentIntentId,
        public string  $paymentAttemptId,
        public int     $amountMinor,
        public string  $currencyCode,
        public string  $payerActorId,
        public string  $correlationId,
        public string  $idempotencyKey,
        public ?string $walletId = null,
        public ?string $stepUpToken = null,
        public ?string $sessionId = null,
    ) {
    }
}
