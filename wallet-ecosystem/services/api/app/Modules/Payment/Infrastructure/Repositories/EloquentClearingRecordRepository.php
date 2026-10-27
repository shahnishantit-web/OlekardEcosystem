<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Repositories;

use App\Modules\Payment\Domain\Contracts\ClearingRecordRepositoryInterface;
use App\Modules\Payment\Infrastructure\Models\ClearingRecordModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentClearingRecordRepository implements ClearingRecordRepositoryInterface
{
    /** @param array<string, mixed> $data */
    public function create(array $data): ClearingRecordModel
    {
        $model = new ClearingRecordModel($data);
        $model->save();
        return $model;
    }

    public function findByAttemptId(string $attemptId): ?ClearingRecordModel
    {
        /** @var ClearingRecordModel|null */
        return ClearingRecordModel::query()->where('payment_attempt_id', $attemptId)->first();
    }

    public function findById(string $id): ?ClearingRecordModel
    {
        /** @var ClearingRecordModel|null */
        return ClearingRecordModel::query()->find($id);
    }

    /** @param array<string, mixed> $filters */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = ClearingRecordModel::query();

        if (isset($filters['provider_code'])) {
            $query->where('provider_code', $filters['provider_code']);
        }

        if (isset($filters['accounting_status'])) {
            $query->where('accounting_status', $filters['accounting_status']);
        }

        if (isset($filters['aged']) && $filters['aged']) {
            $query->whereNotNull('aged_at');
        }

        if (isset($filters['bank_settled'])) {
            if ($filters['bank_settled']) {
                $query->whereNotNull('bank_settled_at');
            } else {
                $query->whereNull('bank_settled_at');
            }
        }

        return $query->orderByDesc('cleared_at')->paginate($perPage);
    }
}
