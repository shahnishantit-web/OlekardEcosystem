<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Domain\Enums\ProviderStatementStatus;
use App\Modules\Payment\Infrastructure\Models\ProviderStatementModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProviderStatementRepositoryInterface
{
    /** @param array<string, mixed> $data */
    public function create(array $data): ProviderStatementModel;

    public function findByHash(string $hash): ?ProviderStatementModel;

    public function findById(string $id): ?ProviderStatementModel;

    /**
     * @param array<string, mixed> $filters
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator;

    /** @param array<string, mixed> $extra */
    public function updateStatus(string $id, ProviderStatementStatus $status, array $extra = []): void;

    public function incrementMatchedLines(string $id, int $matched, int $exceptions): void;
}
