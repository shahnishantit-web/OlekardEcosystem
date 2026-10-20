<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Contracts;

use App\Modules\Ops\Domain\Enums\HealthStatus;
use App\Modules\Ops\Infrastructure\Models\HealthSnapshotModel;

interface HealthSnapshotRepositoryInterface
{
    public function create(string $serviceName, HealthStatus $overallStatus, array $components): HealthSnapshotModel;

    public function latest(string $serviceName): ?HealthSnapshotModel;
}
