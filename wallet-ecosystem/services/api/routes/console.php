<?php

use App\Modules\Integration\Infrastructure\Jobs\DeliverAdminRetryIntentsJob;
use App\Modules\Payment\Application\Jobs\MonitorDisputeDeadlinesJob;
use App\Modules\Payment\Application\Jobs\PayoutReservationExpiryJob;
use App\Modules\Payment\Application\Jobs\SettlementAgeingCheckJob;
use App\Modules\Notification\Application\Jobs\DeliverAdminNotificationEventsJob;
use App\Modules\Ops\Application\Jobs\AlertEvaluationJob;
use App\Modules\Ops\Application\Jobs\BackupRetentionJob;
use App\Modules\Ops\Application\Jobs\BackupSchedulerJob;
use App\Modules\Ops\Application\Jobs\DatabaseMetricsCollectionJob;
use App\Modules\Ops\Application\Jobs\MetricsAggregationJob;
use App\Modules\Ops\Application\Jobs\ProviderHealthCheckJob;
use App\Modules\Ops\Application\Jobs\QueueMetricsCollectionJob;
use App\Modules\Profile\Infrastructure\Jobs\ExpiredContactChallengeCleanupJob;
use App\Modules\Risk\Application\Jobs\DeliverInvestigationEventsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// T122: nullify pending email/mobile fields whose OTP challenge has expired (E01 expires_at)
Schedule::job(new \App\Jobs\OutboxDispatcherJob(), 'outbox')->everyMinute()->withoutOverlapping();

Schedule::job(ExpiredContactChallengeCleanupJob::class)->hourly();

// E30: DevOps & Operations scheduled jobs
// Note: Laravel 11 Schedule::job() returns CallbackEvent — pass connection as 3rd arg, not chained
Schedule::job(MetricsAggregationJob::class, null, 'horizon')->everyMinute();
Schedule::job(DatabaseMetricsCollectionJob::class, null, 'horizon')->everyFiveMinutes();
Schedule::job(QueueMetricsCollectionJob::class, null, 'horizon')->everyFiveMinutes();
Schedule::job(ProviderHealthCheckJob::class, null, 'horizon')->everyFiveMinutes();
Schedule::job(AlertEvaluationJob::class, null, 'horizon')->everyMinute();
Schedule::job(BackupSchedulerJob::class, null, 'horizon')->daily();
Schedule::job(BackupRetentionJob::class, null, 'horizon')->weekly();

// Domain-owned outbox retries survive worker/queue outages after an AML freeze.
Schedule::job(DeliverInvestigationEventsJob::class)->everyMinute()->withoutOverlapping();

Schedule::job(DeliverAdminNotificationEventsJob::class)->everyMinute()->withoutOverlapping();

Schedule::job(DeliverAdminRetryIntentsJob::class)->everyMinute()->withoutOverlapping();

// T043: P16 — Monitor open dispute cases approaching their response deadline (hourly, read-only)
Schedule::job(MonitorDisputeDeadlinesJob::class)->hourly()->withoutOverlapping();

// T045: P17 — Daily settlement ageing check; flags clearing records past the configured threshold
Schedule::job(SettlementAgeingCheckJob::class)->daily()->withoutOverlapping();

// P18: Expire payout reservations that have passed their awaiting_approval window
Schedule::job(PayoutReservationExpiryJob::class)->hourly()->withoutOverlapping();
