<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Repositories;

use App\Modules\Payment\Domain\Contracts\ProviderStatementRepositoryInterface;
use App\Modules\Payment\Domain\Enums\ProviderStatementStatus;
use App\Modules\Payment\Infrastructure\Models\ProviderStatementModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentProviderStatementRepository implements ProviderStatementRepositoryInterface
{
    /** @param array<string, mixed> $data */
    public function create(array $data): ProviderStatementModel
    {
        $model = new ProviderStatementModel($data);
        $model->save();
        return $model;
    }

    public function findByHash(string $hash): ?ProviderStatementModel
    {
        /** @var ProviderStatementModel|null */
        return ProviderStatementModel::query()->where('source_hash', $hash)->first();
    }

    public function findById(string $id): ?ProviderStatementModel
    {
        /** @var ProviderStatementModel|null */
        return ProviderStatementModel::query()->find($id);
    }

    /** @param array<string, mixed> $filters */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = ProviderStatementModel::query();

        if (isset($filters['provider_code'])) {
            $query->where('provider_code', $filters['provider_code']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['currency_code'])) {
            $query->where('currency_code', $filters['currency_code']);
        }

        if (isset($filters['period_from'])) {
            $query->where('period_start', '>=', $filters['period_from']);
        }

        if (isset($filters['period_to'])) {
            $query->where('period_end', '<=', $filters['period_to']);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /** @param array<string, mixed> $extra */
    public function updateStatus(string $id, ProviderStatementStatus $status, array $extra = []): void
    {
        $updates = array_merge(['status' => $status->value], $extra);
        ProviderStatementModel::query()->where('id', $id)->update($updates);
    }

    public function incrementMatchedLines(string $id, int $matched, int $exceptions): void
    {
        ProviderStatementModel::query()->where('id', $id)->update([
            'matched_lines'   => \Illuminate\Support\Facades\DB::raw("matched_lines + {$matched}"),
            'exception_lines' => \Illuminate\Support\Facades\DB::raw("exception_lines + {$exceptions}"),
        ]);
    }
}
