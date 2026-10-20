<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Contracts\DeadLetterRepositoryInterface;
use App\Modules\Platform\Infrastructure\Models\DeadLetterItemModel;
use Illuminate\Pagination\LengthAwarePaginator;

final class EloquentDeadLetterRepository implements DeadLetterRepositoryInterface
{
    public function save(DeadLetterItemModel $item): void
    {
        $item->save();
    }

    public function findById(string $id): ?DeadLetterItemModel
    {
        return DeadLetterItemModel::find($id);
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = DeadLetterItemModel::query()->orderByDesc('created_at');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['operation_type'])) {
            $query->where('operation_type', $filters['operation_type']);
        }

        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        return $query->paginate($perPage);
    }

    public function countByStatus(): array
    {
        return DeadLetterItemModel::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }
}
