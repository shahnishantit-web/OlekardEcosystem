<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Repositories;

use App\Modules\Wallet\Infrastructure\Models\CurrencyPocketModel;

final class EloquentCurrencyPocketRepository
{
    public function findByWalletAndCurrency(string $walletId, string $currencyCode): ?CurrencyPocketModel
    {
        return CurrencyPocketModel::on('wallet')
            ->where('wallet_id', $walletId)
            ->where('currency_code', $currencyCode)
            ->first();
    }

    /** @return CurrencyPocketModel[] */
    public function findAllByWalletId(string $walletId): array
    {
        return CurrencyPocketModel::on('wallet')
            ->where('wallet_id', $walletId)
            ->get()
            ->all();
    }

    public function save(CurrencyPocketModel $pocket): void
    {
        $pocket->save();
    }
}
