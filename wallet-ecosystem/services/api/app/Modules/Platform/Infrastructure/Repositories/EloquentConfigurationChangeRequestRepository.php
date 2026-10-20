<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Contracts\ConfigurationChangeRequestRepositoryInterface;
use App\Modules\Platform\Infrastructure\Persistence\ConfigurationChangeRequestModel;
use Illuminate\Pagination\LengthAwarePaginator;

final class EloquentConfigurationChangeRequestRepository implements ConfigurationChangeRequestRepositoryInterface
{
    public function findById(string $id): ?ConfigurationChangeRequestModel
    {
        return ConfigurationChangeRequestModel::find($id);
    }

    public function findByIdempotencyKey(string $key): ?ConfigurationChangeRequestModel
    {
        return ConfigurationChangeRequestModel::where('idempotency_key', $key)->first();
    }

    public function findPendingByKey(string $configKey): ?ConfigurationChangeRequestModel
    {
        return ConfigurationChangeRequestModel::where('config_key', $configKey)
            ->where('status', 'pending_approval')
            ->first();
    }

    public function lockForUpdate(string $id): ?ConfigurationChangeRequestModel
    {
        return ConfigurationChangeRequestModel::where('id', $id)
            ->lockForUpdate()
            ->first();
    }

    public function save(ConfigurationChangeRequestModel $model): void
    {
        $model->save();
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = ConfigurationChangeRequestModel::query()->orderByDesc('submitted_at');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['domain'])) {
            $query->where('domain', $filters['domain']);
        }

        if (isset($filters['config_key'])) {
            $query->where('config_key', 'like', '%' . $filters['config_key'] . '%');
        }

        if (isset($filters['maker_id'])) {
            $query->where('maker_id', $filters['maker_id']);
        }

        return $query->paginate($perPage);
    }
}
