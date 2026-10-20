<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Repositories;

use App\Modules\Ops\Domain\Contracts\ProviderHealthCheckRepositoryInterface;
use App\Modules\Ops\Domain\Enums\HealthStatus;
use App\Modules\Ops\Infrastructure\Models\ProviderHealthCheckModel;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class EloquentProviderHealthCheckRepository implements ProviderHealthCheckRepositoryInterface
{
    public function record(
        string $providerType,
        HealthStatus $status,
        ?int $responseTimeMs,
        ?string $errorMessage,
        Carbon $checkedAt,
    ): ProviderHealthCheckModel {
        return ProviderHealthCheckModel::create([
            'id'               => Str::orderedUuid()->toString(),
            'provider_type'    => $providerType,
            'status'           => $status->value,
            'response_time_ms' => $responseTimeMs,
            'error_message'    => $errorMessage,
            'checked_at'       => $checkedAt,
            'created_at'       => Carbon::now(),
        ]);
    }

    public function latestForProvider(string $providerType, int $limit = 10): Collection
    {
        return ProviderHealthCheckModel::where('provider_type', $providerType)
            ->orderByDesc('checked_at')
            ->limit($limit)
            ->get();
    }

    public function allLatest(): Collection
    {
        return ProviderHealthCheckModel::selectRaw('DISTINCT ON (provider_type) *')
            ->orderByRaw('provider_type, checked_at DESC')
            ->get();
    }
}
