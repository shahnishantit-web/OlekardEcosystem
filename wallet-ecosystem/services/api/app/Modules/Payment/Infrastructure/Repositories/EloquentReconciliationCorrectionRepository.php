<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Repositories;

use App\Modules\Payment\Domain\Contracts\ReconciliationCorrectionRepositoryInterface;
use App\Modules\Payment\Domain\Enums\CorrectionRequestStatus;
use App\Modules\Payment\Infrastructure\Models\ReconciliationCorrectionRequestModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentReconciliationCorrectionRepository implements ReconciliationCorrectionRepositoryInterface
{
    /** @param array<string, mixed> $data */
    public function create(array $data): ReconciliationCorrectionRequestModel
    {
        $model = new ReconciliationCorrectionRequestModel($data);
        $model->save();
        return $model;
    }

    public function findByIdempotencyKey(string $key): ?ReconciliationCorrectionRequestModel
    {
        /** @var ReconciliationCorrectionRequestModel|null */
        return ReconciliationCorrectionRequestModel::query()->where('idempotency_key', $key)->first();
    }

    public function findById(string $id): ?ReconciliationCorrectionRequestModel
    {
        /** @var ReconciliationCorrectionRequestModel|null */
        return ReconciliationCorrectionRequestModel::query()->find($id);
    }

    public function findByExceptionId(string $exceptionId): ?ReconciliationCorrectionRequestModel
    {
        /** @var ReconciliationCorrectionRequestModel|null */
        return ReconciliationCorrectionRequestModel::query()->where('exception_id', $exceptionId)->first();
    }

    /** @param array<string, mixed> $filters */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = ReconciliationCorrectionRequestModel::query();

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['exception_id'])) {
            $query->where('exception_id', $filters['exception_id']);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /** @param array<string, mixed> $extra */
    public function updateStatus(string $id, CorrectionRequestStatus $status, array $extra = []): void
    {
        $updates = array_merge(['status' => $status->value], $extra);
        ReconciliationCorrectionRequestModel::query()->where('id', $id)->update($updates);
    }
}
