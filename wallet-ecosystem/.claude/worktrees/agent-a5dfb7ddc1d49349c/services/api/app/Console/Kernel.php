<?php

declare(strict_types=1);

namespace App\Console;

use App\Jobs\ConfigurationActivationJob;
use App\Jobs\IdempotencyTtlCleanupJob;
use App\Jobs\OutboxDispatcherJob;
use App\Jobs\OutboxStuckProcessingCleanupJob;
use App\Modules\Identity\Infrastructure\Jobs\OtpChallengeCleanupJob;
use App\Modules\Identity\Infrastructure\Jobs\SessionCleanupJob;
use App\Modules\Platform\Application\Services\ConfigurationService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;

final class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Outbox dispatch — every minute on the critical queue
        $schedule->job(new OutboxDispatcherJob(), 'platform.critical')->everyMinute();

        // Stuck-processing cleanup — interval from configuration or 300s safety fallback
        $timeoutSeconds = app(ConfigurationService::class)->get('events.outbox.processing_timeout_seconds');
        if ($timeoutSeconds === null) {
            $timeoutSeconds = 300;
            Log::warning('kernel.schedule.stuck_processing_cleanup.fallback_timeout', [
                'message' => 'events.outbox.processing_timeout_seconds is not configured; using 300s safety fallback — set this value for explicit operational control',
            ]);
        }

        $schedule->job(new OutboxStuckProcessingCleanupJob(app(ConfigurationService::class)), 'outbox')
            ->everySeconds((int) $timeoutSeconds);

        // Idempotency TTL cleanup — daily at 02:00 UTC
        $schedule->job(new IdempotencyTtlCleanupJob(), 'default')->dailyAt('02:00');

        // Configuration activation — every minute
        $schedule->job(new ConfigurationActivationJob(), 'platform.critical')->everyMinute();

        // Identity: session cleanup — daily at 03:00 UTC (purges terminal sessions older than 90 days)
        $schedule->job(new SessionCleanupJob(), 'default')->dailyAt('03:00');

        // Identity: OTP challenge cleanup — hourly (purges terminal challenges older than 24h)
        $schedule->job(new OtpChallengeCleanupJob(), 'default')->hourly();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
