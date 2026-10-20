<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Jobs;

use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Reporting\Domain\Enums\ReportJobStatus;
use App\Modules\Reporting\Domain\Events\ReportJobCompletedEvent;
use App\Modules\Reporting\Domain\Events\ReportJobFailedEvent;
use App\Modules\Reporting\Infrastructure\Jobs\Concerns\IncrementsFailureCounter;
use App\Modules\Reporting\Infrastructure\Models\ReportJobModel;
use App\Modules\Platform\Concerns\HasCorrelationId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class GenerateRiskReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, IncrementsFailureCounter;
    use HasCorrelationId;

    public int $timeout;

    public function __construct(
        public readonly string $jobId,
        string $correlationId,
    ) {
        $this->correlationId = $correlationId;
        $this->timeout = (int) config('reports.execution_timeout_seconds', 300);
    }

    public function handle(AuditServiceInterface $audit, OutboxEventPublisher $outbox): void
    {
        $this->injectCorrelationContext();

        $job = ReportJobModel::findOrFail($this->jobId);

        DB::connection('platform')->transaction(function () use ($job, $audit): void {
            $job->transitionTo(ReportJobStatus::Processing);
            $job->started_at = now();
            $job->as_of_at   = now();
            $job->save();

            $audit->record(new AuditEventData(
                action:        'reporting.report_job.processing',
                actorType:     'system',
                resourceType:  'report_jobs',
                correlationId: $this->correlationId,
                outcome:       'success',
                actorId:       $job->requested_by_user_id,
                resourceId:    $job->id,
            ));
        });

        try {
            $filters   = $job->filters ?? [];
            $piiAccess = (bool) $job->pii_access;

            $flagCountQuery = DB::connection('platform')
                ->table('risk.aml_flags')
                ->selectRaw('flag_category, COUNT(*) as count')
                ->groupBy('flag_category');

            if (!empty($filters['date_range_start'])) {
                $flagCountQuery->where('created_at', '>=', $filters['date_range_start']);
            }
            if (!empty($filters['date_range_end'])) {
                $flagCountQuery->where('created_at', '<=', $filters['date_range_end'].' 23:59:59');
            }

            $flagCountByCategory = [];
            foreach ($flagCountQuery->get() as $row) {
                $flagCountByCategory[$row->flag_category ?? 'unknown'] = $row->count;
            }

            $velocityBreachCount = DB::connection('platform')
                ->table('risk.velocity_breaches')
                ->when(!empty($filters['date_range_start']), fn ($q) => $q->where('created_at', '>=', $filters['date_range_start']))
                ->when(!empty($filters['date_range_end']), fn ($q) => $q->where('created_at', '<=', $filters['date_range_end'].' 23:59:59'))
                ->count();

            $activeRestrictionsCount = DB::connection('platform')
                ->table('risk.user_restrictions')
                ->where('status', 'active')
                ->count();

            $topFlagged = DB::connection('platform')
                ->table('risk.aml_flags')
                ->selectRaw('user_id, COUNT(*) as flag_count')
                ->groupBy('user_id')
                ->orderByDesc('flag_count')
                ->limit(10)
                ->get()
                ->map(static function ($row) use ($piiAccess): array {
                    $identifier = $piiAccess
                        ? $row->user_id
                        : substr(hash('sha256', (string) $row->user_id), 0, 12);
                    return ['identifier' => $identifier, 'flag_count' => $row->flag_count];
                })
                ->all();

            $result = [
                'flag_count_by_category'       => $flagCountByCategory,
                'velocity_breach_count'         => $velocityBreachCount,
                'users_with_active_restrictions' => $activeRestrictionsCount,
                'top_flagged_users'             => $topFlagged,
                'as_of_at'                      => now()->toIso8601String(),
            ];

            DB::connection('platform')->transaction(function () use ($job, $result, $audit, $outbox): void {
                $job->transitionTo(ReportJobStatus::Complete);
                $job->result_data  = $result;
                $job->row_count    = 1;
                $job->completed_at = now();
                $job->save();

                $audit->record(new AuditEventData(
                    action:        'reporting.report_job.completed',
                    actorType:     'system',
                    resourceType:  'report_jobs',
                    correlationId: $this->correlationId,
                    outcome:       'success',
                    actorId:       $job->requested_by_user_id,
                    resourceId:    $job->id,
                ));

                $outbox->publish(new ReportJobCompletedEvent(
                    jobId:         $job->id,
                    reportType:    $job->report_type,
                    rowCount:      $job->row_count,
                    asOfAt:        $job->as_of_at?->toIso8601String() ?? now()->toIso8601String(),
                    correlationId: $this->correlationId,
                    occurredAt:    now()->toIso8601String(),
                ));
            });
        } catch (\Throwable $e) {
            $failureReason = $e instanceof \Illuminate\Contracts\Queue\TimeoutExceeded
                ? 'execution_timeout'
                : substr($e->getMessage(), 0, 512);

            DB::connection('platform')->transaction(function () use ($job, $failureReason, $audit, $outbox): void {
                $job->transitionTo(ReportJobStatus::Failed);
                $job->failure_reason = $failureReason;
                $job->completed_at   = now();
                $job->save();

                $audit->record(new AuditEventData(
                    action:        'reporting.report_job.failed',
                    actorType:     'system',
                    resourceType:  'report_jobs',
                    correlationId: $this->correlationId,
                    outcome:       'failure',
                    actorId:       $job->requested_by_user_id,
                    resourceId:    $job->id,
                    afterState:    ['failure_reason' => $failureReason],
                ));

                $outbox->publish(new ReportJobFailedEvent(
                    jobId:         $job->id,
                    reportType:    $job->report_type,
                    failureReason: $failureReason,
                    correlationId: $this->correlationId,
                    occurredAt:    now()->toIso8601String(),
                ));
            });

            $this->recordJobFailure($job->report_type, $this->correlationId);
            throw $e;
        }
    }
}
