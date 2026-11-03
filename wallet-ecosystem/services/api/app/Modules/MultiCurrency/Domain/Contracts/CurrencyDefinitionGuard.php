<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Contracts;

use App\Modules\MultiCurrency\Domain\ValueObjects\CurrencyDefinitionSnapshot;

/** Serializes a domain transaction with currency status and precision mutations. */
interface CurrencyDefinitionGuard
{
    /** Must be called inside the caller's transaction; null means the currency does not exist. */
    public function lockAndRead(string $currencyCode, string $transactionConnection): ?CurrencyDefinitionSnapshot;
}
