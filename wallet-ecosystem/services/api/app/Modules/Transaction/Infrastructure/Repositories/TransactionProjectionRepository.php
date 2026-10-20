<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Infrastructure\Repositories;

use App\Modules\Transaction\Infrastructure\Models\TransactionProjectionModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class TransactionProjectionRepository
{
    public function findById(string $id): ?TransactionProjectionModel
    {
        return TransactionProjectionModel::find($id);
    }

    public function findByIdForUpdate(string $id): ?TransactionProjectionModel
    {
        return TransactionProjectionModel::lockForUpdate()->find($id);
    }

    public function upsert(array $attributes, string $id): TransactionProjectionModel
    {
        return TransactionProjectionModel::updateOrCreate(['id' => $id], $attributes);
    }

    public function listForCustomer(
        string $customerId,
        ?string $type,
        ?string $status,
        ?string $search,
        ?string $cursor,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $query = TransactionProjectionModel::forCustomer($customerId)
            ->orderBy('created_at', 'desc');

        if ($type !== null) {
            $query->ofType($type);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($search !== null && $search !== '') {
            $query->search($search);
        }

        return $query->paginate($perPage);
    }
}
