<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Repositories;

use App\Modules\Payment\Domain\Contracts\BankRepositoryInterface;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderModel;
use Illuminate\Pagination\LengthAwarePaginator;

final class EloquentBankRepository implements BankRepositoryInterface
{
    public function findById(string $id): ?PaymentProviderModel
    {
        return PaymentProviderModel::query()->find($id);
    }

    public function findByCode(string $code): ?PaymentProviderModel
    {
        return PaymentProviderModel::query()->where('code', $code)->first();
    }

    /**
     * @param array{status?: string, code?: string, display_name?: string} $filters
     * @return LengthAwarePaginator<PaymentProviderModel>
     */
    public function listPaginated(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = PaymentProviderModel::query();

        if (!empty($filters['status'])) {
            $query->where('bank_lifecycle_status', $filters['status']);
        }

        if (!empty($filters['code'])) {
            $query->where('code', 'ilike', '%'.$filters['code'].'%');
        }

        if (!empty($filters['display_name'])) {
            $query->where('display_name', 'ilike', '%'.$filters['display_name'].'%');
        }

        return $query->orderBy('display_name')->paginate($perPage);
    }

    public function save(PaymentProviderModel $bank): PaymentProviderModel
    {
        $bank->save();

        return $bank;
    }
}
