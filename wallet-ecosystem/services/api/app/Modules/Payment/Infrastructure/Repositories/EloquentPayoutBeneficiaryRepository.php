<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Repositories;

use App\Modules\Payment\Domain\Contracts\PayoutBeneficiaryRepositoryInterface;
use App\Modules\Payment\Infrastructure\Models\PayoutBeneficiaryModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentPayoutBeneficiaryRepository implements PayoutBeneficiaryRepositoryInterface
{
    public function findById(string $id): ?PayoutBeneficiaryModel
    {
        /** @var PayoutBeneficiaryModel|null */
        return PayoutBeneficiaryModel::query()->find($id);
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = PayoutBeneficiaryModel::query();

        if (isset($filters['organisation_id'])) {
            $query->where('organisation_id', $filters['organisation_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['account_type'])) {
            $query->where('account_type', $filters['account_type']);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    public function save(PayoutBeneficiaryModel $model): void
    {
        $model->save();
    }
}
