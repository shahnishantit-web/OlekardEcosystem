<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Contracts;

use App\Modules\MultiCurrency\Infrastructure\Models\CurrencyWalletModel;
use Illuminate\Support\Collection;

interface CurrencyWalletRepositoryInterface
{
    public function findById(string $id): ?CurrencyWalletModel;

    public function findByIdempotencyKey(string $key): ?CurrencyWalletModel;

    public function findByCustomerAndCurrency(string $customerId, string $currencyCode): ?CurrencyWalletModel;

    public function listByCustomer(string $customerId): Collection;

    public function save(CurrencyWalletModel $model): void;

    public function lockForUpdate(string $id): ?CurrencyWalletModel;
}
