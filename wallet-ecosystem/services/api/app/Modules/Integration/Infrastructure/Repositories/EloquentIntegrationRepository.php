<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Repositories;

use App\Modules\Integration\Domain\Contracts\IntegrationRepositoryInterface;
use App\Modules\Integration\Domain\Enums\IntegrationProviderType;
use App\Modules\Integration\Domain\Enums\IntegrationStatus;
use App\Modules\Integration\Infrastructure\Models\IntegrationModel;
use Illuminate\Support\Collection;

final class EloquentIntegrationRepository implements IntegrationRepositoryInterface
{
    public function findById(string $id): ?IntegrationModel
    {
        return IntegrationModel::find($id);
    }

    public function findDefault(IntegrationProviderType $type): ?IntegrationModel
    {
        return IntegrationModel::where('provider_type', $type->value)
            ->where('is_default', true)
            ->where('status', IntegrationStatus::Active->value)
            ->first();
    }

    public function all(array $filters = []): Collection
    {
        $query = IntegrationModel::query()->orderBy('provider_type')->orderBy('created_at', 'desc');

        if (!empty($filters['provider_type'])) {
            $query->where('provider_type', $filters['provider_type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->get();
    }

    public function save(IntegrationModel $model): void
    {
        $model->save();
    }

    public function updateStatus(string $id, IntegrationStatus $status): IntegrationModel
    {
        $model = IntegrationModel::findOrFail($id);
        $model->status = $status;
        $model->save();

        return $model;
    }

    public function demoteDefaultsForType(IntegrationProviderType $type, string $exceptId): void
    {
        IntegrationModel::where('provider_type', $type->value)
            ->where('is_default', true)
            ->where('id', '!=', $exceptId)
            ->update(['is_default' => false]);
    }
}
