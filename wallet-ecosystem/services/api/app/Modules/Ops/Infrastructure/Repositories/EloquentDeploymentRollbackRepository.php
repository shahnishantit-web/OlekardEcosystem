<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Repositories;

use App\Modules\Ops\Domain\Contracts\DeploymentRollbackRepositoryInterface;
use App\Modules\Ops\Domain\Enums\RollbackStatus;
use App\Modules\Ops\Infrastructure\Models\DeploymentRollbackModel;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class EloquentDeploymentRollbackRepository implements DeploymentRollbackRepositoryInterface
{
    public function create(
        string $idempotencyKey,
        string $fromVersion,
        string $toVersion,
        ?string $initiatedBy,
        ?string $reason,
    ): DeploymentRollbackModel {
        return DeploymentRollbackModel::create([
            'id'              => Str::orderedUuid()->toString(),
            'idempotency_key' => $idempotencyKey,
            'from_version'    => $fromVersion,
            'to_version'      => $toVersion,
            'status'          => RollbackStatus::InProgress->value,
            'initiated_by'    => $initiatedBy,
            'reason'          => $reason,
        ]);
    }

    public function findByIdempotencyKey(string $key): ?DeploymentRollbackModel
    {
        return DeploymentRollbackModel::where('idempotency_key', $key)->first();
    }

    public function findInProgress(): ?DeploymentRollbackModel
    {
        return DeploymentRollbackModel::where('status', RollbackStatus::InProgress->value)->first();
    }

    public function markCompleted(string $id): DeploymentRollbackModel
    {
        $rollback = DeploymentRollbackModel::findOrFail($id);
        $rollback->update(['status' => RollbackStatus::Completed->value, 'completed_at' => Carbon::now()]);
        return $rollback->refresh();
    }

    public function markFailed(string $id, string $errorMessage): DeploymentRollbackModel
    {
        $rollback = DeploymentRollbackModel::findOrFail($id);
        $rollback->update([
            'status'        => RollbackStatus::Failed->value,
            'error_message' => $errorMessage,
            'completed_at'  => Carbon::now(),
        ]);
        return $rollback->refresh();
    }

    public function recent(int $limit = 20): Collection
    {
        return DeploymentRollbackModel::orderByDesc('created_at')->limit($limit)->get();
    }
}
