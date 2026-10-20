<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Application\DTOs;

final readonly class CreateCurrencyWalletDTO
{
    public function __construct(
        public string $customerId,
        public string $currencyCode,
        public string $idempotencyKey,
        public string $correlationId,
    ) {}
}
