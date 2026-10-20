<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Contracts;

use App\Modules\Ops\Domain\Enums\BackupType;
use App\Modules\Ops\Infrastructure\Models\BackupJobModel;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;

interface BackupJobRepositoryInterface
{
    public function create(string $idempotencyKey, BackupType $type): BackupJobModel;

    public function findByIdempotencyKey(string $key): ?BackupJobModel;

    public function markRunning(string $id): BackupJobModel;

    public function markCompleted(string $id, string $storagePath, int $sizeBytes): BackupJobModel;

    public function markFailed(string $id, string $reason): BackupJobModel;

    public function findOrFail(string $id): BackupJobModel;

    /** @return LengthAwarePaginator<BackupJobModel> */
    public function paginate(int $perPage): LengthAwarePaginator;

    /** @return \Illuminate\Support\Collection<int, BackupJobModel> */
    public function olderThan(Carbon $cutoff): \Illuminate\Support\Collection;
}
