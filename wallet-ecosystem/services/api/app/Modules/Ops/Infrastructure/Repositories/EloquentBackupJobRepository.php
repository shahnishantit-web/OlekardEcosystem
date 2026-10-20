<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Repositories;

use App\Modules\Ops\Domain\Contracts\BackupJobRepositoryInterface;
use App\Modules\Ops\Domain\Enums\BackupJobStatus;
use App\Modules\Ops\Domain\Enums\BackupType;
use App\Modules\Ops\Domain\Exceptions\BackupJobNotFoundException;
use App\Modules\Ops\Infrastructure\Models\BackupJobModel;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class EloquentBackupJobRepository implements BackupJobRepositoryInterface
{
    public function create(string $idempotencyKey, BackupType $type): BackupJobModel
    {
        return BackupJobModel::create([
            'id'              => Str::orderedUuid()->toString(),
            'idempotency_key' => $idempotencyKey,
            'type'            => $type->value,
            'status'          => BackupJobStatus::Pending->value,
        ]);
    }

    public function findByIdempotencyKey(string $key): ?BackupJobModel
    {
        return BackupJobModel::where('idempotency_key', $key)->first();
    }

    public function findOrFail(string $id): BackupJobModel
    {
        return BackupJobModel::find($id) ?? throw new BackupJobNotFoundException($id);
    }

    public function markRunning(string $id): BackupJobModel
    {
        $job = $this->findOrFail($id);
        $job->update(['status' => BackupJobStatus::Running->value, 'started_at' => Carbon::now()]);
        return $job->refresh();
    }

    public function markCompleted(string $id, string $storagePath, int $sizeBytes): BackupJobModel
    {
        $job = $this->findOrFail($id);
        $job->update([
            'status'       => BackupJobStatus::Completed->value,
            'storage_path' => $storagePath,
            'size_bytes'   => $sizeBytes,
            'completed_at' => Carbon::now(),
        ]);
        return $job->refresh();
    }

    public function markFailed(string $id, string $errorMessage): BackupJobModel
    {
        $job = $this->findOrFail($id);
        $job->update([
            'status'        => BackupJobStatus::Failed->value,
            'error_message' => $errorMessage,
            'completed_at'  => Carbon::now(),
        ]);
        return $job->refresh();
    }

    public function paginate(int $perPage): LengthAwarePaginator
    {
        return BackupJobModel::orderByDesc('created_at')->paginate($perPage);
    }

    /** @return Collection<int, BackupJobModel> */
    public function olderThan(Carbon $cutoff): Collection
    {
        return BackupJobModel::where('created_at', '<', $cutoff)
            ->where('status', BackupJobStatus::Completed->value)
            ->get();
    }
}
