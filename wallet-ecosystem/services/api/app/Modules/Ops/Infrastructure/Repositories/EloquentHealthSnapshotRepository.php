<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Repositories;

use App\Modules\Ops\Domain\Contracts\HealthSnapshotRepositoryInterface;
use App\Modules\Ops\Domain\Enums\HealthStatus;
use App\Modules\Ops\Infrastructure\Models\HealthSnapshotModel;
use Carbon\Carbon;
use Illuminate\Support\Str;

final class EloquentHealthSnapshotRepository implements HealthSnapshotRepositoryInterface
{
    public function create(string $serviceName, HealthStatus $overallStatus, array $components): HealthSnapshotModel
    {
        return HealthSnapshotModel::create([
            'id'             => Str::orderedUuid()->toString(),
            'service_name'   => $serviceName,
            'overall_status' => $overallStatus->value,
            'components'     => $components,
            'checked_at'     => Carbon::now(),
        ]);
    }

    public function latest(string $serviceName): ?HealthSnapshotModel
    {
        return HealthSnapshotModel::where('service_name', $serviceName)
            ->orderByDesc('checked_at')
            ->first();
    }
}
