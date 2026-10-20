<?php

declare(strict_types=1);

use App\Modules\Configuration\Domain\Contracts\ConfigurationServiceInterface;

$e28 = static function (string $key, mixed $default): mixed {
    try {
        return app(ConfigurationServiceInterface::class)->getOrDefault($key, $default);
    } catch (\Throwable) {
        return $default;
    }
};

return [
    'execution_timeout_seconds' => $e28('reports.execution_timeout_seconds', 300),
    'concurrent_jobs_per_user'  => $e28('reports.concurrent_jobs_per_user', 3),
    'max_rows_default'          => $e28('reports.max_rows_default', 100000),
    'export_link_ttl_seconds'   => $e28('reports.export_link_ttl_seconds', 3600),
    'export_file_retention_days' => $e28('reports.export_file_retention_days', 7),
    'alert_failure_threshold'   => $e28('reports.alert_failure_threshold', 5),
    'alert_window_minutes'      => $e28('reports.alert_window_minutes', 5),
];
