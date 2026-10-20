<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\Jobs;

use App\Modules\Ops\Domain\Contracts\BackupJobRepositoryInterface;
use App\Modules\Ops\Domain\Contracts\BackupStorageAdapterInterface;
use App\Modules\Platform\Application\Services\ConfigurationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class BackupRetentionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 300;

    public function handle(
        BackupJobRepositoryInterface $jobs,
        BackupStorageAdapterInterface $storage,
        ConfigurationService $config,
    ): void {
        $retentionDays = (int) $config->getOrDefault('ops.backup.retention_days', 30);
        $cutoff        = Carbon::now()->subDays($retentionDays);

        $expired = $jobs->olderThan($cutoff);

        foreach ($expired as $job) {
            try {
                if ($job->storage_path !== null) {
                    $storage->delete($job->storage_path);
                }
                $job->delete();
            } catch (\Throwable $e) {
                Log::warning('Failed to purge expired backup job', [
                    'job_id' => $job->id,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        Log::info('Backup retention complete', ['purged' => $expired->count(), 'retention_days' => $retentionDays]);
    }
}
