<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Console\Commands;

use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Reporting\Domain\Enums\ReportJobStatus;
use App\Modules\Reporting\Domain\Enums\ReportType;
use App\Modules\Reporting\Domain\Events\ReportScheduleTriggeredEvent;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateAgentReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateCommissionReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateFinancialSummaryReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateKycReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateReconciliationReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateRiskReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateSettlementReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateTransactionReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateUserReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateWalletReportJob;
use App\Modules\Reporting\Infrastructure\Models\ReportJobModel;
use App\Modules\Reporting\Infrastructure\Models\ReportScheduleModel;
use App\Modules\Reporting\Infrastructure\Models\ScheduleRunModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProcessReportSchedulesCommand extends Command
{
    protected $signature   = 'reports:process-schedules {--dry-run : Preview without dispatching jobs}';
    protected $description = 'Dispatch report jobs for active schedules due to run.';

    public function __construct(
        private readonly OutboxEventPublisher $outbox,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        $dueSchedules = ReportScheduleModel::query()
            ->where('status', 'active')
            ->where('next_run_at', '<=', now())
            ->get();

        if ($dueSchedules->isEmpty()) {
            $this->info('No schedules due.');
            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($dueSchedules as $schedule) {
            if ($isDryRun) {
                $this->line("DRY-RUN: {$schedule->name} ({$schedule->report_type}) — next_run_at: {$schedule->next_run_at}");
                continue;
            }

            $correlationId = (string) Str::uuid();

            try {
                DB::connection('platform')->transaction(function () use ($schedule, $correlationId): void {
                    $job = ReportJobModel::create([
                        'definition_id'        => null,
                        'report_type'          => $schedule->report_type,
                        'requested_by_user_id' => $schedule->owner_user_id,
                        'correlation_id'       => $correlationId,
                        'filters'              => $schedule->filters ?? [],
                        'pii_access'           => false,
                        'status'               => ReportJobStatus::Requested,
                    ]);

                    $this->dispatchJobHandler(
                        ReportType::from($schedule->report_type),
                        $job->id,
                        $correlationId
                    );

                    ScheduleRunModel::create([
                        'schedule_id' => $schedule->id,
                        'job_id'      => $job->id,
                        'outcome'     => 'dispatched',
                    ]);

                    $this->outbox->publish(new ReportScheduleTriggeredEvent(
                        scheduleId:   $schedule->id,
                        jobId:        $job->id,
                        triggeredAt:  now()->toIso8601String(),
                        correlationId: $correlationId,
                        occurredAt:   now()->toIso8601String(),
                    ));

                    $schedule->last_run_at = now();
                    $schedule->next_run_at = $schedule->computeNextRunAt(\Carbon\CarbonImmutable::now());
                    $schedule->save();
                });

                $dispatched++;
            } catch (\Throwable $e) {
                ScheduleRunModel::create([
                    'schedule_id'    => $schedule->id,
                    'outcome'        => 'failed',
                    'failure_reason' => substr($e->getMessage(), 0, 512),
                ]);

                $this->error("Failed to dispatch schedule [{$schedule->id}]: {$e->getMessage()}");
            }
        }

        if (!$isDryRun) {
            $this->info("Dispatched {$dispatched} report job(s) from " . $dueSchedules->count() . ' schedule(s).');
        }

        return self::SUCCESS;
    }

    private function dispatchJobHandler(ReportType $type, string $jobId, string $correlationId): void
    {
        match ($type) {
            ReportType::WalletReport         => GenerateWalletReportJob::dispatch($jobId, $correlationId),
            ReportType::TransactionReport    => GenerateTransactionReportJob::dispatch($jobId, $correlationId),
            ReportType::UserReport           => GenerateUserReportJob::dispatch($jobId, $correlationId),
            ReportType::AgentReport          => GenerateAgentReportJob::dispatch($jobId, $correlationId),
            ReportType::CommissionReport     => GenerateCommissionReportJob::dispatch($jobId, $correlationId),
            ReportType::KycReport            => GenerateKycReportJob::dispatch($jobId, $correlationId),
            ReportType::ReconciliationReport => GenerateReconciliationReportJob::dispatch($jobId, $correlationId),
            ReportType::SettlementReport     => GenerateSettlementReportJob::dispatch($jobId, $correlationId),
            ReportType::RiskReport           => GenerateRiskReportJob::dispatch($jobId, $correlationId),
            ReportType::FinancialSummary     => GenerateFinancialSummaryReportJob::dispatch($jobId, $correlationId),
        };
    }
}
