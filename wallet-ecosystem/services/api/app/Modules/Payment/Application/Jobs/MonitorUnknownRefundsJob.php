<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Jobs;

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * T039: US8 — Scheduled job that alerts operators when refunds are stuck in 'unknown' state.
 *
 * Emits payment.refund.unknown_count metric with the count of unknown refunds
 * older than payment.refund_unknown_alert_threshold_hours (default 24h).
 * Logs at WARNING level with refund IDs and reserved amounts.
 */
final class MonitorUnknownRefundsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function uniqueId(): string
    {
        return 'monitor_unknown_refunds';
    }

    public function handle(
        ConfigurationServiceInterface $config,
        MetricsCollectorInterface     $metrics,
    ): void {
        $thresholdHours = (int) $config->getOrDefault('payment.refund_unknown_alert_threshold_hours', '24');
        $cutoff         = now()->subHours($thresholdHours);

        $rows = DB::connection('payment')
            ->table('payment.payment_refunds')
            ->where('status', 'unknown')
            ->where('unknown_at', '<=', $cutoff)
            ->select(['id', 'amount_minor', 'unknown_at', 'payment_attempt_id'])
            ->limit(500)
            ->get();

        $count = $rows->count();

        $metrics->gauge('payment.refund.unknown_count', (float) $count);

        if ($count === 0) {
            return;
        }

        $totalReserved = $rows->sum('amount_minor');

        Log::warning('Unknown refunds requiring operator investigation', [
            'count'          => $count,
            'total_reserved' => $totalReserved,
            'threshold_hours' => $thresholdHours,
            'refund_ids'     => $rows->pluck('id')->take(50)->all(),
        ]);
    }
}
