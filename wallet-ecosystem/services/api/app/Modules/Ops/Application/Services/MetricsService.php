<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\Services;

use App\Modules\Ops\Domain\Contracts\MetricRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class MetricsService
{
    public function __construct(
        private readonly MetricRepositoryInterface $repository,
    ) {}

    /** @return Collection<int, mixed> */
    public function query(
        string $serviceName,
        ?string $metricName,
        string $granularity,
        Carbon $from,
        Carbon $to,
    ): Collection {
        return $this->repository->query($serviceName, $metricName, $granularity, $from, $to);
    }
}
