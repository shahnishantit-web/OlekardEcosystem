<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Infrastructure\Models\ClearingRecordModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ClearingRecordRepositoryInterface
{
    /** @param array<string, mixed> $data */
    public function create(array $data): ClearingRecordModel;

    public function findByAttemptId(string $attemptId): ?ClearingRecordModel;

    public function findById(string $id): ?ClearingRecordModel;

    /**
     * @param array<string, mixed> $filters
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator;
}
