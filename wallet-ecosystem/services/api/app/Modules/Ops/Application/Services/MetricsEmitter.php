<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\Services;

use App\Modules\Ops\Domain\Contracts\MetricRepositoryInterface;
use Carbon\Carbon;
use Throwable;

final class MetricsEmitter
{
    public function __construct(
        private readonly MetricRepositoryInterface $repository,
    ) {}

    public function emit(string $serviceName, string $metricName, float $value, string $unit, ?Carbon $at = null): void
    {
        try {
            $this->repository->record($serviceName, $metricName, $value, $unit, 'raw', $at ?? Carbon::now());
        } catch (Throwable) {
            // Never let metric recording crash the application.
        }
    }
}
