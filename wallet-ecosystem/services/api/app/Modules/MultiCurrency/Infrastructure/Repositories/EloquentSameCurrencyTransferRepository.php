<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Infrastructure\Repositories;

use App\Modules\MultiCurrency\Domain\Contracts\SameCurrencyTransferRepositoryInterface;
use App\Modules\MultiCurrency\Infrastructure\Models\SameCurrencyTransferModel;

final class EloquentSameCurrencyTransferRepository implements SameCurrencyTransferRepositoryInterface
{
    public function findByIdempotencyKey(string $key): ?SameCurrencyTransferModel
    {
        return SameCurrencyTransferModel::where('idempotency_key', $key)->first();
    }

    public function save(SameCurrencyTransferModel $model): void
    {
        $model->save();
    }
}
