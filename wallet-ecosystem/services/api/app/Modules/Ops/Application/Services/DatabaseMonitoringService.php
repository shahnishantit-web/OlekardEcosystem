<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\Services;

use Illuminate\Support\Facades\DB;

final class DatabaseMonitoringService
{
    public function __construct(
        private readonly MetricsEmitter $emitter,
    ) {}

    public function collect(): void
    {
        $rows = DB::connection('platform')->select("
            SELECT
                count(*) FILTER (WHERE state = 'active')  AS active_connections,
                count(*)                                   AS total_connections
            FROM pg_stat_activity
        ");

        $active = (int) ($rows[0]->active_connections ?? 0);

        $maxRows = DB::connection('platform')->select('SHOW max_connections');
        $max     = (int) ($maxRows[0]->max_connections ?? 100);

        $storageRows = DB::connection('platform')->select("
            SELECT pg_database_size(current_database()) AS size_bytes
        ");
        $sizeBytes = (int) ($storageRows[0]->size_bytes ?? 0);

        $slowRows = DB::connection('platform')->select("
            SELECT count(*) AS slow_count
            FROM pg_stat_activity
            WHERE state = 'active'
              AND now() - query_start > interval '1 second'
        ");
        $slowCount = (int) ($slowRows[0]->slow_count ?? 0);

        $this->emitter->emit('database', 'db.active_connections', (float) $active,    'count');
        $this->emitter->emit('database', 'db.max_connections',    (float) $max,       'count');
        $this->emitter->emit('database', 'db.storage_bytes',      (float) $sizeBytes, 'bytes');
        $this->emitter->emit('database', 'db.slow_query_count',   (float) $slowCount, 'count');
    }
}
