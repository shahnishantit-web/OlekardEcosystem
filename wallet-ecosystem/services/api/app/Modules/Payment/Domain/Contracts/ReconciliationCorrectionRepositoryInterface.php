<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Domain\Enums\CorrectionRequestStatus;
use App\Modules\Payment\Infrastructure\Models\ReconciliationCorrectionRequestModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ReconciliationCorrectionRepositoryInterface
{
    /** @param array<string, mixed> $data */
    public function create(array $data): ReconciliationCorrectionRequestModel;

    public function findByIdempotencyKey(string $key): ?ReconciliationCorrectionRequestModel;

    public function findById(string $id): ?ReconciliationCorrectionRequestModel;

    public function findByExceptionId(string $exceptionId): ?ReconciliationCorrectionRequestModel;

    /**
     * @param array<string, mixed> $filters
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator;

    /** @param array<string, mixed> $extra */
    public function updateStatus(string $id, CorrectionRequestStatus $status, array $extra = []): void;
}
