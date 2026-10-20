<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Services;

use App\Modules\Admin\Application\Jobs\ExportTransactionsJob;
use App\Modules\Admin\Domain\Contracts\AdminMetricsInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class ExportService
{
    private const TTL_SECONDS   = 90000;
    private const CACHE_PREFIX  = 'admin:export:';

    public function __construct(
        private readonly AdminMetricsInterface $metrics,
    ) {}

    public function requestExport(
        array $filters,
        string $format,
        string $requestedBy,
    ): array {
        $exportId = (string) Uuid::uuid7();

        $status = [
            'export_id'    => $exportId,
            'status'       => 'queued',
            'format'       => $format,
            'filters'      => $filters,
            'requested_by' => $requestedBy,
            'queued_at'    => now()->toISOString(),
            'download_url' => null,
            'expires_at'   => null,
        ];

        Cache::put(self::CACHE_PREFIX . $exportId, $status, self::TTL_SECONDS);

        ExportTransactionsJob::dispatch($exportId, $filters, $format, $requestedBy)
            ->onQueue('admin');

        $this->metrics->exportRows(0, $format);

        return $status;
    }

    public function getExportStatus(string $exportId): ?array
    {
        return Cache::get(self::CACHE_PREFIX . $exportId);
    }

    public function markCompleted(string $exportId, string $filePath, int $rowCount, string $format): void
    {
        $status = Cache::get(self::CACHE_PREFIX . $exportId);

        if ($status === null) {
            return;
        }

        $status['status']       = 'completed';
        $status['row_count']    = $rowCount;
        $status['download_url'] = $filePath;
        $status['expires_at']   = now()->addSeconds(self::TTL_SECONDS)->toISOString();
        $status['completed_at'] = now()->toISOString();

        Cache::put(self::CACHE_PREFIX . $exportId, $status, self::TTL_SECONDS);

        $this->metrics->exportRows($rowCount, $format);
    }

    public function markFailed(string $exportId, string $error): void
    {
        $status = Cache::get(self::CACHE_PREFIX . $exportId);

        if ($status === null) {
            return;
        }

        $status['status'] = 'failed';
        $status['error']  = $error;

        Cache::put(self::CACHE_PREFIX . $exportId, $status, 3600);
    }
}
