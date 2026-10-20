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
use Illuminate\Support\Facades\Log;

final class GenerateFinancialSummaryReportJob implements ShouldQueue
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
            $filters = $job->filters ?? [];

            // Double-entry validation: total debits must equal total credits per currency.
            $query = DB::connection('ledger')
                ->table('ledger.journal_lines as jl')
                ->join('ledger.journals as j', 'jl.journal_id', '=', 'j.id')
                ->selectRaw('jl.currency_code, jl.direction, SUM(jl.amount) as total')
                ->groupBy('jl.currency_code', 'jl.direction');

            if (!empty($filters['date_range_start'])) {
                $query->where('j.created_at', '>=', $filters['date_range_start']);
            }
            if (!empty($filters['date_range_end'])) {
                $query->where('j.created_at', '<=', $filters['date_range_end'].' 23:59:59');
            }

            $rows = $query->get();

            $byCurrency = [];
            foreach ($rows as $row) {
                $currency  = $row->currency_code;
                $direction = $row->direction; // 'debit' or 'credit'
                $total     = (float) $row->total;

                $byCurrency[$currency] ??= ['debit' => 0.0, 'credit' => 0.0];
                $byCurrency[$currency][$direction] += $total;
            }

            $overallBalanceValidates = true;
            $currencyResults         = [];

            foreach ($byCurrency as $currency => $totals) {
                $debit    = $totals['debit']  ?? 0.0;
                $credit   = $totals['credit'] ?? 0.0;
                $validates = abs($debit - $credit) <= 0.001;

                if (!$validates) {
                    $overallBalanceValidates = false;
                    Log::warning('Financial summary double-entry validation failed', [
                        'job_id'      => $job->id,
                        'currency'    => $currency,
                        'total_debit' => $debit,
                        'total_credit' => $credit,
                    ]);
                }

                $currencyResults[] = [
                    'currency'          => $currency,
                    'total_debit'       => round($debit, 2),
                    'total_credit'      => round($credit, 2),
                    'balance_validates' => $validates,
                ];
            }

            $result = [
                'currencies'               => $currencyResults,
                'overall_balance_validates' => $overallBalanceValidates,
                'as_of_at'                 => $job->as_of_at?->toIso8601String(),
            ];

            DB::connection('platform')->transaction(function () use ($job, $result, $currencyResults, $audit, $outbox): void {
                $job->transitionTo(ReportJobStatus::Complete);
                $job->result_data  = $result;
                $job->row_count    = count($currencyResults);
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
