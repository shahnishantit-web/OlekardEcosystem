<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Contracts;

use App\Modules\Ops\Infrastructure\Models\ServiceMetricModel;
use Carbon\Carbon;
use Illuminate\Support\Collection;

interface MetricRepositoryInterface
{
    public function record(string $serviceName, string $metricName, float $value, string $unit, string $granularity, Carbon $recordedAt): ServiceMetricModel;

    /** @return Collection<int, ServiceMetricModel> */
    public function query(string $serviceName, ?string $metricName, string $granularity, Carbon $from, Carbon $to): Collection;

    public function aggregateRawToMinute(Carbon $bucketStart): void;

    public function aggregateMinuteToHour(Carbon $bucketStart): void;

    /** @return float Average value or 0.0 if no data */
    public function averageOverWindow(string $serviceName, string $metricName, Carbon $from, Carbon $to): float;
}
