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
use Illuminate\Support\LazyCollection;

final class GenerateUserReportJob implements ShouldQueue
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

            $query = DB::connection('platform')
                ->table('identity.users as u')
                ->leftJoin('kyc.kyc_verifications as k', 'k.user_id', '=', 'u.id')
                ->select('u.id', 'u.email', 'u.mobile_number', 'u.status as account_status', 'u.created_at',
                    DB::raw('MAX(k.tier) as kyc_tier'));

            if (!empty($filters['date_range_start'])) {
                $query->where('u.created_at', '>=', $filters['date_range_start']);
            }
            if (!empty($filters['date_range_end'])) {
                $query->where('u.created_at', '<=', $filters['date_range_end'].' 23:59:59');
            }
            if (!empty($filters['kyc_status'])) {
                $query->where('k.result', $filters['kyc_status']);
            }
            if (!empty($filters['account_status'])) {
                $query->where('u.status', $filters['account_status']);
            }

            $query->groupBy('u.id', 'u.email', 'u.mobile_number', 'u.status', 'u.created_at');

            $rowCount = DB::connection('platform')->table('identity.users')->count();

            $rows = $rowCount > 10000
                ? LazyCollection::make(fn () => yield from $query->cursor())->map(fn ($row) => (array) $row)->all()
                : $query->get()->map(fn ($row) => (array) $row)->all();

            $resultRows = array_map(static function (array $row) use ($piiAccess): array {
                return [
                    'user_id'           => $row['id'] ?? null,
                    'email'             => $piiAccess ? ($row['email'] ?? null) : '***',
                    'mobile_number'     => $piiAccess ? ($row['mobile_number'] ?? null) : '***',
                    'kyc_tier'          => $row['kyc_tier'] ?? null,
                    'account_status'    => $row['account_status'] ?? null,
                    'registration_date' => $row['created_at'] ?? null,
                ];
            }, $rows);

            DB::connection('platform')->transaction(function () use ($job, $resultRows, $audit, $outbox): void {
                $job->transitionTo(ReportJobStatus::Complete);
                $job->result_data  = ['users' => $resultRows, 'as_of_at' => $job->as_of_at?->toIso8601String()];
                $job->row_count    = count($resultRows);
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
                    afterState:    ['row_count' => $job->row_count],
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
