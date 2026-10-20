<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\Jobs;

use App\Modules\Ops\Application\Services\BackupService;
use App\Modules\Ops\Domain\Enums\BackupType;
use App\Modules\Platform\Application\Services\ConfigurationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class BackupSchedulerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 600;

    public function handle(BackupService $backupService, ConfigurationService $config): void
    {
        $enabled = (bool) $config->getOrDefault('ops.backup.enabled', true);

        if (!$enabled) {
            Log::info('Backup scheduler skipped — ops.backup.enabled is false');
            return;
        }

        $typeValue = (string) $config->getOrDefault('ops.backup.type', BackupType::Full->value);
        $type      = BackupType::tryFrom($typeValue) ?? BackupType::Full;

        $idempotencyKey = 'backup_' . date('Y-m-d_H') . '_' . Str::random(8);

        $backupService->run($idempotencyKey, $type);
    }
}
