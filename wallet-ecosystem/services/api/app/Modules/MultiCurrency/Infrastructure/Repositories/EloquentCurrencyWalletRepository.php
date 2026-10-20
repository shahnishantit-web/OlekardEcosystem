<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Infrastructure\Repositories;

use App\Modules\MultiCurrency\Domain\Contracts\CurrencyWalletRepositoryInterface;
use App\Modules\MultiCurrency\Infrastructure\Models\CurrencyWalletModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentCurrencyWalletRepository implements CurrencyWalletRepositoryInterface
{
    public function findById(string $id): ?CurrencyWalletModel
    {
        return CurrencyWalletModel::find($id);
    }

    public function findByIdempotencyKey(string $key): ?CurrencyWalletModel
    {
        return CurrencyWalletModel::where('idempotency_key', $key)->first();
    }

    public function findByCustomerAndCurrency(string $customerId, string $currencyCode): ?CurrencyWalletModel
    {
        return CurrencyWalletModel::where('customer_id', $customerId)
            ->where('currency_code', strtoupper($currencyCode))
            ->first();
    }

    public function listByCustomer(string $customerId): Collection
    {
        return CurrencyWalletModel::where('customer_id', $customerId)
            ->where('is_active', true)
            ->orderBy('currency_code')
            ->get();
    }

    public function save(CurrencyWalletModel $model): void
    {
        $model->save();
    }

    public function lockForUpdate(string $id): ?CurrencyWalletModel
    {
        return CurrencyWalletModel::on('platform')
            ->where('id', $id)
            ->lockForUpdate()
            ->first();
    }
}
