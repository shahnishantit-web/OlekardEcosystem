<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Infrastructure\Repositories;

use App\Modules\MultiCurrency\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\MultiCurrency\Infrastructure\Models\CurrencyModel;
use Illuminate\Support\Collection;
use Ramsey\Uuid\Uuid;

final class EloquentCurrencyRepository implements CurrencyRepositoryInterface
{
    public function findByCode(string $code): ?CurrencyModel
    {
        return CurrencyModel::where('code', strtoupper($code))->first();
    }

    public function listActive(): Collection
    {
        return CurrencyModel::where('is_active', true)->orderBy('code')->get();
    }

    public function listAll(): Collection
    {
        return CurrencyModel::orderBy('code')->get();
    }

    public function upsert(array $attributes): CurrencyModel
    {
        $code  = strtoupper($attributes['code']);
        $model = CurrencyModel::firstOrNew(['code' => $code]);

        if (! $model->exists) {
            $model->id = (string) Uuid::uuid7();
        }

        $model->fill($attributes);
        $model->code = $code;
        $model->save();

        return $model;
    }
}
