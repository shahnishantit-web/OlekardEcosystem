<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Jobs;

use App\Modules\Audit\Domain\Events\ComplianceReportGeneratedEvent;
use App\Modules\Audit\Infrastructure\Models\AuditEventModel;
use App\Modules\Audit\Infrastructure\Models\ComplianceReportModel;
use App\Modules\Audit\Infrastructure\Models\ComplianceStatusModel;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class GenerateComplianceReportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 60;

    public function __construct(
        public readonly string $reportId,
        public readonly string $correlationId,
    ) {}

    public function handle(OutboxEventPublisher $outbox): void
    {
        $report = ComplianceReportModel::findOrFail($this->reportId);
        $report->status = 'generating';
        $report->save();

        $start       = $report->date_range_start;
        $end         = $report->date_range_end.' 23:59:59';

        // Aggregate using indexed queries — no N+1, pure SQL aggregates
        $eventCounts = DB::connection('platform')
            ->table('audit.audit_events')
            ->whereBetween('occurred_at', [$start, $end])
            ->selectRaw('event_category, COUNT(*) as count')
            ->groupBy('event_category')
            ->get()
            ->keyBy('event_category')
            ->map(fn ($row) => (int) $row->count)
            ->all();

        $riskDistribution = DB::connection('platform')
            ->table('audit.compliance_status')
            ->selectRaw('aml_risk_level, COUNT(*) as count')
            ->groupBy('aml_risk_level')
            ->get()
            ->keyBy('aml_risk_level')
            ->map(fn ($row) => (int) $row->count)
            ->all();

        $kycDistribution = DB::connection('platform')
            ->table('audit.compliance_status')
            ->selectRaw('kyc_tier, COUNT(*) as count')
            ->groupBy('kyc_tier')
            ->get()
            ->keyBy('kyc_tier')
            ->map(fn ($row) => (int) $row->count)
            ->all();

        $reportData = [
            'date_range_start'    => $report->date_range_start,
            'date_range_end'      => $report->date_range_end,
            'event_counts_by_category' => $eventCounts,
            'compliance_aml_risk_distribution' => $riskDistribution,
            'compliance_kyc_tier_distribution' => $kycDistribution,
            'generated_at'        => now()->toIso8601String(),
        ];

        $report->report_data  = $reportData;
        $report->status       = 'completed';
        $report->generated_at = now();
        $report->save();

        $outbox->publish(new ComplianceReportGeneratedEvent(
            reportId:            $report->id,
            reportType:          $report->report_type,
            requestedByActorId:  $report->requested_by_actor_id,
            occurredAtIso:       now()->toIso8601String(),
            correlationId:       $this->correlationId,
        ));
    }
}
