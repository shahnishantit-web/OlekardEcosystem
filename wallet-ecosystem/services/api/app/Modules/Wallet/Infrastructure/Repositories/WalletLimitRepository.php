<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Repositories;

use App\Modules\Wallet\Domain\Enums\WalletLimitType;
use App\Modules\Wallet\Infrastructure\Models\WalletLimitModel;

final class WalletLimitRepository
{
    /** @return WalletLimitModel[] */
    public function getByWalletId(string $walletId): array
    {
        return WalletLimitModel::on('wallet')
            ->where('wallet_id', $walletId)
            ->get()
            ->all();
    }

    public function getByWalletAndCurrency(string $walletId, string $currencyCode): array
    {
        return WalletLimitModel::on('wallet')
            ->where('wallet_id', $walletId)
            ->where('currency_code', $currencyCode)
            ->get()
            ->all();
    }

    public function findByType(string $walletId, string $currencyCode, WalletLimitType $type): ?WalletLimitModel
    {
        return WalletLimitModel::on('wallet')
            ->where('wallet_id', $walletId)
            ->where('currency_code', $currencyCode)
            ->where('limit_type', $type->value)
            ->first();
    }

    public function upsert(WalletLimitModel $limit): void
    {
        $limit->save();
    }
}
