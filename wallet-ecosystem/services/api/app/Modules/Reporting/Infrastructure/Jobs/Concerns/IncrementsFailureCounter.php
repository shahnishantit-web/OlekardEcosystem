<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Jobs\Concerns;

use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Reporting\Domain\Events\ReportFailureThresholdExceededEvent;
use Illuminate\Support\Facades\Redis;

trait IncrementsFailureCounter
{
    protected function recordJobFailure(string $reportType, string $correlationId): void
    {
        $threshold     = (int) config('reports.alert_failure_threshold', 5);
        $windowMinutes = (int) config('reports.alert_window_minutes', 5);
        $minuteBucket  = (int) floor(time() / 60);
        $keyPrefix     = "reports:failures:{$reportType}:";
        $bucketKey     = $keyPrefix.$minuteBucket;
        $ttl           = $windowMinutes * 60;

        Redis::incr($bucketKey);
        Redis::expire($bucketKey, $ttl);

        $windowStart = $minuteBucket - $windowMinutes + 1;
        $total       = 0;

        for ($bucket = $windowStart; $bucket <= $minuteBucket; $bucket++) {
            $total += (int) (Redis::get($keyPrefix.$bucket) ?? 0);
        }

        if ($total >= $threshold) {
            $pattern = $keyPrefix.'*';
            $keys    = Redis::keys($pattern);
            if (!empty($keys)) {
                Redis::del(...$keys);
            }

            app(OutboxEventPublisher::class)->publish(new ReportFailureThresholdExceededEvent(
                reportType:    $reportType,
                failureCount:  $total,
                windowMinutes: $windowMinutes,
                threshold:     $threshold,
                correlationId: $correlationId,
                occurredAt:    now()->toIso8601String(),
            ));
        }
    }
}
