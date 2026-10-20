<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

use App\Modules\Platform\Infrastructure\Persistence\ConfigurationChangeRequestModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface ConfigurationChangeRequestRepositoryInterface
{
    public function findById(string $id): ?ConfigurationChangeRequestModel;

    public function findByIdempotencyKey(string $key): ?ConfigurationChangeRequestModel;

    /** Returns the single pending request for $configKey, or null if none exists. */
    public function findPendingByKey(string $configKey): ?ConfigurationChangeRequestModel;

    /** Acquires a row-level lock for concurrent approval prevention (FR-009). */
    public function lockForUpdate(string $id): ?ConfigurationChangeRequestModel;

    public function save(ConfigurationChangeRequestModel $model): void;

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;
}
