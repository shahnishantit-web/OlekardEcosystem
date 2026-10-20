<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Application\DTOs;

final readonly class FxQuoteConfirmDTO
{
    public function __construct(
        public string $customerId,
        public string $quoteId,
        public string $idempotencyKey,
        public string $correlationId,
    ) {}
}
