<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Repositories;

use App\Modules\Ops\Domain\Contracts\MetricRepositoryInterface;
use App\Modules\Ops\Infrastructure\Models\ServiceMetricModel;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentMetricRepository implements MetricRepositoryInterface
{
    public function record(string $serviceName, string $metricName, float $value, string $unit, string $granularity, Carbon $recordedAt): ServiceMetricModel
    {
        return ServiceMetricModel::create([
            'id'           => Str::orderedUuid()->toString(),
            'service_name' => $serviceName,
            'metric_name'  => $metricName,
            'value'        => $value,
            'unit'         => $unit,
            'granularity'  => $granularity,
            'recorded_at'  => $recordedAt,
            'created_at'   => Carbon::now(),
        ]);
    }

    public function query(string $serviceName, ?string $metricName, string $granularity, Carbon $from, Carbon $to): Collection
    {
        $query = ServiceMetricModel::where('service_name', $serviceName)
            ->where('granularity', $granularity)
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at');

        if ($metricName !== null) {
            $query->where('metric_name', $metricName);
        }

        return $query->get();
    }

    public function aggregateRawToMinute(Carbon $bucketStart): void
    {
        $bucketEnd = $bucketStart->copy()->addMinute();

        DB::connection('platform')->statement("
            INSERT INTO ops.service_metrics (id, service_name, metric_name, value, unit, granularity, recorded_at, created_at)
            SELECT uuid_generate_v7(), service_name, metric_name, AVG(value), MAX(unit), 'minute', :bucket_start, NOW()
            FROM ops.service_metrics
            WHERE granularity = 'raw'
              AND recorded_at >= :from
              AND recorded_at <  :to
            GROUP BY service_name, metric_name
            ON CONFLICT DO NOTHING
        ", ['bucket_start' => $bucketStart, 'from' => $bucketStart, 'to' => $bucketEnd]);
    }

    public function aggregateMinuteToHour(Carbon $bucketStart): void
    {
        $bucketEnd = $bucketStart->copy()->addHour();

        DB::connection('platform')->statement("
            INSERT INTO ops.service_metrics (id, service_name, metric_name, value, unit, granularity, recorded_at, created_at)
            SELECT uuid_generate_v7(), service_name, metric_name, AVG(value), MAX(unit), 'hour', :bucket_start, NOW()
            FROM ops.service_metrics
            WHERE granularity = 'minute'
              AND recorded_at >= :from
              AND recorded_at <  :to
            GROUP BY service_name, metric_name
            ON CONFLICT DO NOTHING
        ", ['bucket_start' => $bucketStart, 'from' => $bucketStart, 'to' => $bucketEnd]);
    }

    public function averageOverWindow(string $serviceName, string $metricName, Carbon $from, Carbon $to): float
    {
        return (float) ServiceMetricModel::where('service_name', $serviceName)
            ->where('metric_name', $metricName)
            ->whereBetween('recorded_at', [$from, $to])
            ->avg('value');
    }
}
