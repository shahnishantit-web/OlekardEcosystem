<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain\Adapters;

final readonly class ProviderTransactionDto
{
    public function __construct(
        public string $providerReference,
        public string $amount,
        public string $currencyCode,
        public string $transactionDate,
        public string $itemType = 'provider_transaction',
        public array  $metadata = [],
    ) {}
}
