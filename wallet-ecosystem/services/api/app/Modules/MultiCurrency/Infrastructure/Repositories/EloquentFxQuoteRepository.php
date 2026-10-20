<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Infrastructure\Repositories;

use App\Modules\MultiCurrency\Domain\Contracts\FxQuoteRepositoryInterface;
use App\Modules\MultiCurrency\Infrastructure\Models\FxQuoteModel;

final class EloquentFxQuoteRepository implements FxQuoteRepositoryInterface
{
    public function findById(string $id): ?FxQuoteModel
    {
        return FxQuoteModel::find($id);
    }

    public function findByIdempotencyKey(string $key): ?FxQuoteModel
    {
        return FxQuoteModel::where('idempotency_key', $key)->first();
    }

    public function save(FxQuoteModel $model): void
    {
        $model->save();
    }
}
