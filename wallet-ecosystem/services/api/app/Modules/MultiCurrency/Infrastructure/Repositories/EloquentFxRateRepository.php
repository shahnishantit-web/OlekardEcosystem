<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Infrastructure\Repositories;

use App\Modules\MultiCurrency\Domain\Contracts\FxRateRepositoryInterface;
use App\Modules\MultiCurrency\Domain\Enums\FxRateStatus;
use App\Modules\MultiCurrency\Infrastructure\Models\FxRateModel;
use Illuminate\Support\Collection;

final class EloquentFxRateRepository implements FxRateRepositoryInterface
{
    public function findById(string $id): ?FxRateModel
    {
        return FxRateModel::find($id);
    }

    public function findByChangeRequestId(string $changeRequestId): ?FxRateModel
    {
        return FxRateModel::where('change_request_id', $changeRequestId)->first();
    }

    public function findActivePair(string $from, string $to): ?FxRateModel
    {
        return FxRateModel::where('from_currency_code', strtoupper($from))
            ->where('to_currency_code', strtoupper($to))
            ->where('status', FxRateStatus::Active->value)
            ->where('effective_from', '<=', now())
            ->whereNull('effective_to')
            ->whereHas('fromCurrency', fn ($q) => $q->where('is_active', true))
            ->whereHas('toCurrency', fn ($q) => $q->where('is_active', true))
            ->first();
    }

    public function listActive(): Collection
    {
        return FxRateModel::where('status', FxRateStatus::Active->value)
            ->orderBy('from_currency_code')
            ->orderBy('to_currency_code')
            ->get();
    }

    public function listAll(): Collection
    {
        return FxRateModel::orderBy('from_currency_code')
            ->orderBy('to_currency_code')
            ->orderBy('effective_from', 'desc')
            ->get();
    }

    public function save(FxRateModel $model): void
    {
        $model->save();
    }

    public function supersedeCurrentRate(string $from, string $to): void
    {
        FxRateModel::where('from_currency_code', strtoupper($from))
            ->where('to_currency_code', strtoupper($to))
            ->where('status', FxRateStatus::Active->value)
            ->update([
                'status' => FxRateStatus::Superseded->value,
                'effective_to' => now(),
            ]);
    }

    public function deactivateCurrentRate(string $from, string $to): void
    {
        $this->supersedeCurrentRate($from, $to);
    }
}
