<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Contracts;

use App\Modules\MultiCurrency\Infrastructure\Models\SameCurrencyTransferModel;

interface SameCurrencyTransferRepositoryInterface
{
    public function findByIdempotencyKey(string $key): ?SameCurrencyTransferModel;

    public function save(SameCurrencyTransferModel $model): void;
}
