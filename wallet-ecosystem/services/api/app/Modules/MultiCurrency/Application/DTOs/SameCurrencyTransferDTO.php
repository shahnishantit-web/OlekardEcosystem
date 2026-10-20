<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Application\DTOs;

final readonly class SameCurrencyTransferDTO
{
    public function __construct(
        public string $customerId,
        public string $fromWalletId,
        public string $toWalletId,
        public string $amount,
        public string $idempotencyKey,
        public string $correlationId,
    ) {}
}
