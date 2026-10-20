<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Contracts\ConfigurationApprovalRepositoryInterface;
use App\Modules\Platform\Infrastructure\Persistence\ConfigurationApprovalModel;

final class EloquentConfigurationApprovalRepository implements ConfigurationApprovalRepositoryInterface
{
    public function findByIdempotencyKey(string $key): ?ConfigurationApprovalModel
    {
        return ConfigurationApprovalModel::where('idempotency_key', $key)->first();
    }

    public function findByChangeRequest(string $changeRequestId): array
    {
        return ConfigurationApprovalModel::where('change_request_id', $changeRequestId)
            ->orderBy('decided_at')
            ->get()
            ->all();
    }

    public function save(ConfigurationApprovalModel $model): void
    {
        $model->save();
    }
}
