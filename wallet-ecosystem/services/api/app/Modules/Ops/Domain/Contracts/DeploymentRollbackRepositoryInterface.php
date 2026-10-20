<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Contracts;

use App\Modules\Ops\Infrastructure\Models\DeploymentRollbackModel;
use Illuminate\Support\Collection;

interface DeploymentRollbackRepositoryInterface
{
    public function create(string $idempotencyKey, string $fromVersion, string $toVersion, ?string $initiatedBy, ?string $reason): DeploymentRollbackModel;

    public function findByIdempotencyKey(string $key): ?DeploymentRollbackModel;

    public function findInProgress(): ?DeploymentRollbackModel;

    public function markCompleted(string $id): DeploymentRollbackModel;

    public function markFailed(string $id, string $errorMessage): DeploymentRollbackModel;

    /** @return Collection<int, DeploymentRollbackModel> */
    public function recent(int $limit = 20): Collection;
}
