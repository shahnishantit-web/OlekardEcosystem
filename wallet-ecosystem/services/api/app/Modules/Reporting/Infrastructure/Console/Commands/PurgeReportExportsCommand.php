<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Console\Commands;

use App\Modules\Reporting\Infrastructure\Models\ReportExportModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class PurgeReportExportsCommand extends Command
{
    protected $signature   = 'reports:purge-exports';
    protected $description = 'Delete export files whose file retention period has expired.';

    public function handle(): int
    {
        $expired = ReportExportModel::query()
            ->whereNotNull('file_retention_expires_at')
            ->where('file_retention_expires_at', '<=', now())
            ->whereNull('purged_at')
            ->get();

        $purgedCount = 0;
        foreach ($expired as $export) {
            if ($export->storage_path) {
                try {
                    Storage::disk('reports')->delete($export->storage_path);
                } catch (\Throwable $e) {
                    Log::warning('Failed to delete export file', [
                        'export_id'    => $export->id,
                        'storage_path' => $export->storage_path,
                        'error'        => $e->getMessage(),
                    ]);
                }
            }

            $export->purged_at = now();
            $export->save();
            $purgedCount++;
        }

        $this->info("Purged {$purgedCount} export(s).");
        Log::info('reports:purge-exports completed', ['purged_count' => $purgedCount]);

        return self::SUCCESS;
    }
}
