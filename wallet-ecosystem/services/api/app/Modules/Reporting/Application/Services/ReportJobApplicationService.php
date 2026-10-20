<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Services;

use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Reporting\Domain\Contracts\ReportingModuleContract;
use App\Modules\Reporting\Domain\Enums\ReportJobStatus;
use App\Modules\Reporting\Domain\Enums\ReportType;
use App\Modules\Reporting\Domain\Events\ReportJobRequestedEvent;
use App\Modules\Reporting\Domain\Exceptions\ConcurrentJobLimitExceededException;
use App\Modules\Reporting\Domain\Exceptions\ReportJobCancelException;
use App\Modules\Reporting\Domain\Exceptions\ReportJobNotFoundException;
use App\Modules\Reporting\Domain\Exceptions\RowLimitExceededException;
use App\Modules\Reporting\Infrastructure\Models\ReportDefinitionModel;
use App\Modules\Reporting\Infrastructure\Models\ReportJobModel;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReportJobApplicationService implements ReportingModuleContract
{
    public function __construct(
        private readonly AuditServiceInterface $audit,
        private readonly OutboxEventPublisher  $outbox,
    ) {}

    public function submit(
        string     $userId,
        ReportType $type,
        array      $filters,
        bool       $piiAccess,
        string     $correlationId,
    ): ReportJobModel {
        $definition = ReportDefinitionModel::active()->where('type', $type->value)->firstOrFail();

        $this->validateFilters($filters, $definition->filter_schema);

        $rowCount = $this->estimateRowCount($type, $filters);
        $limit    = (int) config('reports.max_rows_default', 100000);
        if ($rowCount > $limit) {
            throw new RowLimitExceededException($rowCount, $limit);
        }

        $concurrentLimit = (int) config('reports.concurrent_jobs_per_user', 3);

        $job = DB::connection('platform')->transaction(function () use (
            $userId, $type, $filters, $piiAccess, $correlationId, $definition, $concurrentLimit
        ): ReportJobModel {
            $activeCount = ReportJobModel::query()
                ->select('id')
                ->where('requested_by_user_id', $userId)
                ->whereIn('status', [ReportJobStatus::Requested->value, ReportJobStatus::Processing->value])
                ->lockForUpdate()
                ->get()
                ->count();

            if ($activeCount >= $concurrentLimit) {
                throw new ConcurrentJobLimitExceededException($activeCount, $concurrentLimit);
            }

            $job                        = new ReportJobModel();
            $job->definition_id         = $definition->id;
            $job->report_type           = $type->value;
            $job->requested_by_user_id  = $userId;
            $job->correlation_id        = $correlationId;
            $job->filters               = $filters;
            $job->status                = ReportJobStatus::Requested;
            $job->pii_access            = $piiAccess;
            $job->save();

            $this->outbox->publish(new ReportJobRequestedEvent(
                jobId:         $job->id,
                reportType:    $type->value,
                userId:        $userId,
                correlationId: $correlationId,
                occurredAt:    now()->toIso8601String(),
            ));

            $this->audit->record(new AuditEventData(
                action:       'reporting.report_job.requested',
                actorType:    'user',
                resourceType: 'report_jobs',
                correlationId: $correlationId,
                outcome:      'success',
                actorId:      $userId,
                resourceId:   $job->id,
                afterState:   ['type' => $type->value, 'status' => ReportJobStatus::Requested->value],
            ));

            return $job;
        });

        return $job;
    }

    public function getJob(string $jobId, string $userId): ReportJobModel
    {
        $job = ReportJobModel::query()
            ->where('id', $jobId)
            ->where('requested_by_user_id', $userId)
            ->first();

        if ($job === null) {
            throw new ReportJobNotFoundException($jobId);
        }

        return $job;
    }

    public function cancel(string $jobId, string $userId, string $correlationId): void
    {
        $job = $this->getJob($jobId, $userId);

        if ($job->status !== ReportJobStatus::Requested) {
            throw new ReportJobCancelException($jobId);
        }

        DB::connection('platform')->transaction(function () use ($job, $userId, $correlationId): void {
            $job->status         = ReportJobStatus::Failed;
            $job->failure_reason = 'cancelled_by_user';
            $job->completed_at   = now();
            $job->save();

            $this->audit->record(new AuditEventData(
                action:        'reporting.report_job.cancelled',
                actorType:     'user',
                resourceType:  'report_jobs',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $userId,
                resourceId:    $job->id,
                afterState:    ['status' => ReportJobStatus::Failed->value, 'failure_reason' => 'cancelled_by_user'],
            ));
        });
    }

    public function listJobs(string $userId, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = ReportJobModel::query()
            ->where('requested_by_user_id', $userId)
            ->orderByDesc('created_at');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['report_type'])) {
            $query->where('report_type', $filters['report_type']);
        }

        return $query->paginate($perPage);
    }

    public function getJobStatus(string $jobId): ?ReportJobStatus
    {
        $job = ReportJobModel::query()->find($jobId);

        return $job?->status;
    }

    public function listJobsForUser(string $userId, array $filters): LengthAwarePaginator
    {
        return $this->listJobs($userId, $filters);
    }

    private function validateFilters(array $filters, array $schema): void
    {
        $required = $schema['required'] ?? [];
        $missing  = [];

        foreach ($required as $field) {
            if (!array_key_exists($field, $filters) || $filters[$field] === null || $filters[$field] === '') {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            throw ValidationException::withMessages(
                array_fill_keys($missing, ['This field is required.'])
            );
        }
    }

    private function estimateRowCount(ReportType $type, array $filters): int
    {
        $tableMap = [
            ReportType::UserReport->value           => ['identity',       'identity.users'],
            ReportType::WalletReport->value         => ['wallet',         'wallet.wallets'],
            ReportType::TransactionReport->value    => ['transaction',    'transaction.transaction_projections'],
            ReportType::AgentReport->value          => ['agent',          'agent.agents'],
            ReportType::CommissionReport->value     => ['agent',          'agent.agents'],
            ReportType::KycReport->value            => ['kyc',            'kyc.kyc_cases'],
            ReportType::ReconciliationReport->value => ['reconciliation', 'reconciliation.reconciliation_items'],
            ReportType::SettlementReport->value     => ['reconciliation', 'reconciliation.settlement_batches'],
            ReportType::RiskReport->value           => ['risk',           'risk.risk_alerts'],
            ReportType::FinancialSummary->value     => ['ledger',         'ledger.journals'],
        ];

        [$connection, $table] = $tableMap[$type->value] ?? ['platform', 'identity.users'];

        $query = DB::connection($connection)->table($table);

        if (!empty($filters['date_range_start'])) {
            $query->where('created_at', '>=', $filters['date_range_start']);
        }

        if (!empty($filters['date_range_end'])) {
            $query->where('created_at', '<=', $filters['date_range_end'].' 23:59:59');
        }

        return (int) $query->count();
    }
}
