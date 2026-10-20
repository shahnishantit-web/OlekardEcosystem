<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application\Services;

use App\Modules\Audit\Domain\Exceptions\ComplianceReportNotFoundException;
use App\Modules\Audit\Infrastructure\Jobs\GenerateComplianceReportJob;
use App\Modules\Audit\Infrastructure\Models\ComplianceReportModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ComplianceReportService
{
    public function requestReport(
        string $reportType,
        string $dateRangeStart,
        string $dateRangeEnd,
        array  $filters,
        string $idempotencyKey,
        string $actorId,
        string $correlationId,
    ): ComplianceReportModel {
        Log::info('compliance_report.requested', [
            'report_type'      => $reportType,
            'date_range_start' => $dateRangeStart,
            'date_range_end'   => $dateRangeEnd,
            'actor_id'         => $actorId,
            'correlation_id'   => $correlationId,
        ]);

        $existing = ComplianceReportModel::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $report = new ComplianceReportModel([
            'report_type'           => $reportType,
            'status'                => 'pending',
            'requested_by_actor_id' => $actorId,
            'date_range_start'      => $dateRangeStart,
            'date_range_end'        => $dateRangeEnd,
            'filters'               => $filters,
            'idempotency_key'       => $idempotencyKey,
        ]);
        $report->save();

        $asyncThresholdDays = (int) config('audit.report.async_threshold_days', 1);
        $rangeInDays = (int) ceil(
            (strtotime($dateRangeEnd) - strtotime($dateRangeStart)) / 86400
        );

        if ($rangeInDays <= $asyncThresholdDays) {
            // Inline generation for short date ranges
            GenerateComplianceReportJob::dispatchSync($report->id, $correlationId);
            $report->refresh();
        } else {
            GenerateComplianceReportJob::dispatch($report->id, $correlationId);
        }

        Log::channel('stderr')->debug('metric', [
            'metric'      => 'compliance_reports_generated_total',
            'report_type' => $reportType,
            'mode'        => $rangeInDays <= $asyncThresholdDays ? 'sync' : 'async',
        ]);

        return $report;
    }

    public function getReport(string $reportId): ComplianceReportModel
    {
        $report = ComplianceReportModel::find($reportId);
        if ($report === null) {
            throw new ComplianceReportNotFoundException($reportId);
        }
        return $report;
    }
}
