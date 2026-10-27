<?php

declare(strict_types=1);

namespace App\Console;

use App\Jobs\ConfigurationActivationJob;
use App\Jobs\DeadLetterCleanupJob;
use App\Jobs\IdempotencyTtlCleanupJob;
use App\Jobs\LedgerIntegrityJob;
use App\Jobs\OutboxDispatcherJob;
use App\Jobs\OutboxStuckProcessingCleanupJob;
use App\Modules\Funding\Application\Jobs\FundingTransactionTimeoutJob;
use App\Modules\Identity\Infrastructure\Jobs\OtpChallengeCleanupJob;
use App\Modules\Identity\Infrastructure\Jobs\SessionCleanupJob;
use App\Modules\Platform\Application\Services\ConfigurationService;
use App\Modules\Risk\Application\Jobs\EscalateOverdueAlertsJob;
use App\Modules\Risk\Application\Services\WatchlistScreenerService;
use App\Modules\Transfer\Application\Jobs\TransferTransactionTimeoutJob;
use App\Modules\Payment\Application\Jobs\PaymentAttemptCrashRecoveryJob;
use App\Modules\Payment\Application\Jobs\RepairPendingAccountingJob;
use App\Modules\Withdrawal\Application\Jobs\WithdrawalTransactionTimeoutJob;
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

        // Risk: SLA escalation — every 15 minutes
        $schedule->job(new EscalateOverdueAlertsJob(), 'risk')->everyFifteenMinutes();

        // Risk: retrospective watchlist scan — daily at 01:00 UTC
        $schedule->call(static function (): void {
            app(WatchlistScreenerService::class)->screenRetrospectively();
        })->dailyAt('01:00')->name('risk.watchlist.retrospective_screen');

        // Security: purge expired PII — daily at 04:00 UTC (T076, FR-014)
        $schedule->command('app:purge-expired-pii')->dailyAt('04:00');

        // E27: Ledger integrity scan — hourly on critical queue (FR-009, SC-006)
        $schedule->job(new LedgerIntegrityJob(correlationId: (string) \Ramsey\Uuid\Uuid::uuid7()), 'platform.critical')->hourly();

        // E27 US4: Dead-letter TTL cleanup — daily at 05:00 UTC (FR-005)
        $schedule->job(new DeadLetterCleanupJob(), 'default')->dailyAt('05:00');

        // E28: Configuration escalation check — hourly (FR-015, US5/AC3)
        $schedule->command('configuration:escalation-check')->hourly();

        // P07: Crash recovery — every minute; withoutOverlapping() prevents scan pile-up
        $schedule->job(new PaymentAttemptCrashRecoveryJob(), 'default')->everyMinute()->withoutOverlapping();

        // P08: Accounting repair scan — every 5 minutes; withoutOverlapping() prevents pile-up
        $schedule->job(new RepairPendingAccountingJob(), 'default')->everyFiveMinutes()->withoutOverlapping();

        // P07: Prune expired idempotency records — weekly at 02:30 UTC
        $schedule->command('payment:prune-idempotency-records')->weeklyOn(0, '02:30');

        // E27 US3: Orphan/timeout detection — every 5 minutes on reliability queue (FR-004, FR-006)
        $schedule->job(new FundingTransactionTimeoutJob(correlationId: (string) \Ramsey\Uuid\Uuid::uuid7()), 'reliability')->everyFiveMinutes();
        $schedule->job(new TransferTransactionTimeoutJob(correlationId: (string) \Ramsey\Uuid\Uuid::uuid7()), 'reliability')->everyFiveMinutes();
        $schedule->job(new WithdrawalTransactionTimeoutJob(correlationId: (string) \Ramsey\Uuid\Uuid::uuid7()), 'reliability')->everyFiveMinutes();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
