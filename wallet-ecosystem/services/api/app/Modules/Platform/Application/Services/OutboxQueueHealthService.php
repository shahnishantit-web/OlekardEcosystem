<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Services;

use App\Modules\Platform\Domain\Contracts\OutboxQueueHealthServiceInterface;
use Illuminate\Support\Facades\DB;

final class OutboxQueueHealthService implements OutboxQueueHealthServiceInterface
{
    public function getHealthMetrics(?string $eventName = null): array
    {
        $staleThresholdSeconds = (int) config('platform.outbox_stuck_threshold_seconds', 300);

        $sql = <<<SQL
            SELECT
                event_name,
                COUNT(*) FILTER (WHERE status IN ('pending', 'failed'))                                           AS pending_count,
                COUNT(*) FILTER (WHERE status = 'dead_letter')                                                    AS dead_letter_count,
                COUNT(*) FILTER (WHERE status = 'processing'
                    AND processing_started_at < NOW() - INTERVAL '{$staleThresholdSeconds} seconds')             AS stuck_count,
                MIN(created_at) FILTER (WHERE status IN ('pending', 'failed'))                                    AS oldest_pending_at
            FROM platform.outbox_events
SQL;

        $bindings = [];
        if ($eventName !== null) {
            $sql       .= ' WHERE event_name = ?';
            $bindings[] = $eventName;
        }

        $sql .= ' GROUP BY event_name';

        $rows = DB::connection('platform')->select($sql, $bindings);

        return array_map(static function (object $row) {
            $oldestPendingAt        = $row->oldest_pending_at;
            $oldestPendingAgeSeconds = $oldestPendingAt !== null
                ? (int) now()->diffInSeconds(\Carbon\Carbon::parse($oldestPendingAt))
                : null;

            return [
                'event_name'                => $row->event_name,
                'pending_count'             => (int) $row->pending_count,
                'dead_letter_count'         => (int) $row->dead_letter_count,
                'stuck_count'               => (int) $row->stuck_count,
                'oldest_pending_age_seconds' => $oldestPendingAgeSeconds,
            ];
        }, $rows);
    }
}
