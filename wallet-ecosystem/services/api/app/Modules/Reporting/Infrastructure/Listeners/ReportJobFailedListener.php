<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Listeners;

use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Reporting\Domain\Events\ReportJobFailedEvent;
use App\Modules\Reporting\Infrastructure\Models\ReportScheduleModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ReportJobFailedListener implements ShouldQueue
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(ReportJobFailedEvent $event): void
    {
        $run = DB::connection('platform')
            ->table('reporting.schedule_runs')
            ->where('job_id', $event->jobId)
            ->first();

        if ($run === null) {
            return;
        }

        $schedule = ReportScheduleModel::find($run->schedule_id);
        if ($schedule === null) {
            Log::warning('reporting.notification.schedule_not_found_for_failure', [
                'job_id'         => $event->jobId,
                'schedule_run_id' => $run->id,
                'correlation_id' => $event->correlationId,
            ]);
            return;
        }

        $recipients = $schedule->recipients ?? [];
        foreach ($recipients as $recipient) {
            $userId = $recipient['user_id'] ?? null;
            if ($userId === null) {
                continue;
            }

            $this->notificationService->createFromEvent(new CreateNotificationCommand(
                sourceEventId:    $event->jobId,
                sourceEventType:  ReportJobFailedEvent::class,
                notificationType: 'report.job.failed',
                category:         NotificationCategory::Operational,
                mandatory:        false,
                recipientId:      $userId,
                variables:        [
                    'report_type'    => $event->reportType,
                    'failure_reason' => $event->failureReason,
                    'job_id'         => $event->jobId,
                ],
                correlationId: $event->correlationId,
            ));
        }
    }
}
