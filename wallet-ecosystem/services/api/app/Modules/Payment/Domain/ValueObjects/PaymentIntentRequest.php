<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

final readonly class PaymentIntentRequest
{
    public function __construct(
        public string $purposeType,
        public string $purposeReference,
        public string $organisationId,
        public string $payerActorId,
        public string $currencyCode,
        public string $idempotencyKey,
        public string $correlationId,
        public ?string $providerCode = null,
        public ?string $providerAccountId = null,
        public array   $metadata = [],
    ) {
    }
}
