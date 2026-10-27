<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Infrastructure\Models\PayoutRequestModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PayoutRequestRepositoryInterface
{
    public function findById(string $id): ?PayoutRequestModel;

    public function findByIdempotencyKey(string $key): ?PayoutRequestModel;

    public function sumActiveReservationsForFund(string $fundReferenceId, string $currencyCode): int;

    public function save(PayoutRequestModel $model): void;

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator;
}
