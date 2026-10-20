<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Infrastructure\Repositories;

use App\Modules\MultiCurrency\Domain\Contracts\FxTransactionRepositoryInterface;
use App\Modules\MultiCurrency\Infrastructure\Models\FxTransactionModel;
use Illuminate\Pagination\LengthAwarePaginator;

final class EloquentFxTransactionRepository implements FxTransactionRepositoryInterface
{
    public function findById(string $id): ?FxTransactionModel
    {
        return FxTransactionModel::find($id);
    }

    public function findByIdempotencyKey(string $key): ?FxTransactionModel
    {
        return FxTransactionModel::where('idempotency_key', $key)->first();
    }

    public function save(FxTransactionModel $model): void
    {
        $model->save();
    }

    public function paginateByCustomer(string $customerId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = FxTransactionModel::where('customer_id', $customerId)
            ->orderBy('created_at', 'desc');

        if (! empty($filters['from_currency'])) {
            $query->where('from_currency_code', strtoupper($filters['from_currency']));
        }
        if (! empty($filters['to_currency'])) {
            $query->where('to_currency_code', strtoupper($filters['to_currency']));
        }
        if (! empty($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        return $query->paginate($perPage);
    }
}
