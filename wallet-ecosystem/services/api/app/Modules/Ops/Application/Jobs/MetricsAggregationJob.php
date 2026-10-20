<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\Jobs;

use App\Modules\Ops\Domain\Contracts\MetricRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class MetricsAggregationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(MetricRepositoryInterface $repository): void
    {
        $now = Carbon::now();

        // Aggregate the last hour of raw → minute buckets
        for ($i = 59; $i >= 0; $i--) {
            $bucketStart = $now->copy()->subMinutes($i)->startOfMinute();
            $repository->aggregateRawToMinute($bucketStart);
        }

        // Aggregate the previous hour's minutes → single hour bucket
        $previousHour = $now->copy()->subHour()->startOfHour();
        $repository->aggregateMinuteToHour($previousHour);
    }
}
