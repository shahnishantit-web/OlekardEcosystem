<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Application\DTOs;

final readonly class FxQuoteRequestDTO
{
    public function __construct(
        public string $customerId,
        public string $fromCurrencyCode,
        public string $toCurrencyCode,
        public string $fromAmount,
        public string $idempotencyKey,
        public string $correlationId,
    ) {}
}
