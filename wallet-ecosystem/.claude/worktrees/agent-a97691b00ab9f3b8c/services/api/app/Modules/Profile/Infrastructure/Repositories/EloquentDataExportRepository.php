<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Repositories;

use App\Modules\Profile\Infrastructure\Models\DataExportModel;
use Illuminate\Support\Collection;
use Ramsey\Uuid\Uuid;

final class EloquentDataExportRepository
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): DataExportModel
    {
        $model                 = new DataExportModel();
        $model->id             = (string) Uuid::uuid7();
        $model->user_id        = $attributes['user_id'];
        $model->status         = $attributes['status'] ?? 'processing';
        $model->download_key   = $attributes['download_key'] ?? null;
        $model->expires_at     = $attributes['expires_at'] ?? null;
        $model->requested_at   = $attributes['requested_at'] ?? now();
        $model->completed_at   = $attributes['completed_at'] ?? null;
        $model->correlation_id = $attributes['correlation_id'];
        $model->save();
        return $model->fresh();
    }

    public function findById(string $id): ?DataExportModel
    {
        return DataExportModel::find($id);
    }

    /** @return Collection<int, DataExportModel> */
    public function findByUserIdAndStatus(string $userId, string $status): Collection
    {
        return DataExportModel::where('user_id', $userId)
            ->where('status', $status)
            ->get();
    }

    /** @param array<string, mixed> $attributes */
    public function update(string $id, array $attributes): bool
    {
        return (bool) DataExportModel::where('id', $id)->update($attributes);
    }
}
