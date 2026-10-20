<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

use App\Modules\Platform\Infrastructure\Persistence\ConfigurationApprovalModel;

interface ConfigurationApprovalRepositoryInterface
{
    public function findByIdempotencyKey(string $key): ?ConfigurationApprovalModel;

    /** @return array<int, ConfigurationApprovalModel> */
    public function findByChangeRequest(string $changeRequestId): array;

    public function save(ConfigurationApprovalModel $model): void;
}
