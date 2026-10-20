<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Contracts;

use App\Modules\Ops\Domain\Enums\HealthStatus;
use App\Modules\Ops\Infrastructure\Models\ProviderHealthCheckModel;
use Carbon\Carbon;
use Illuminate\Support\Collection;

interface ProviderHealthCheckRepositoryInterface
{
    public function record(string $providerType, HealthStatus $status, ?int $responseTimeMs, ?string $errorMessage, Carbon $checkedAt): ProviderHealthCheckModel;

    /** @return Collection<int, ProviderHealthCheckModel> */
    public function latestForProvider(string $providerType, int $limit = 10): Collection;

    /** @return Collection<int, ProviderHealthCheckModel> */
    public function allLatest(): Collection;
}
