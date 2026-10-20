<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Contracts;

use App\Modules\MultiCurrency\Infrastructure\Models\FxTransactionModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface FxTransactionRepositoryInterface
{
    public function findById(string $id): ?FxTransactionModel;

    public function findByIdempotencyKey(string $key): ?FxTransactionModel;

    public function save(FxTransactionModel $model): void;

    public function paginateByCustomer(string $customerId, array $filters, int $perPage): LengthAwarePaginator;
}
