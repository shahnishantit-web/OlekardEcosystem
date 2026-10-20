<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Audit;

use App\Modules\Audit\Domain\Contracts\AuditModuleContract;
use App\Modules\Audit\Domain\Enums\AuditActorType;
use App\Modules\Audit\Domain\Enums\AuditEventCategory;
use App\Modules\Audit\Domain\Enums\AuditOutcome;
use App\Modules\Audit\Domain\ValueObjects\AuditEntry;
use App\Modules\Audit\Infrastructure\Jobs\GenerateComplianceReportJob;
use App\Modules\Audit\Infrastructure\Models\ComplianceReportModel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Docker-gated: requires real DB with audit.* schema and audit.compliance_reports table.
 * Run inside Docker: php artisan test --filter=ComplianceReportJobTest
 */
final class ComplianceReportJobTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.hmac_key' => 'test-hmac-report-job']);
    }

    public function test_job_aggregates_audit_events_and_marks_report_completed(): void
    {
        $audit   = $this->app->make(AuditModuleContract::class);
        $actorId = (string) Uuid::uuid7();
        $start   = CarbonImmutable::now()->subDays(29)->startOfDay();

        // Seed 30 days of financial audit events (one per day) within the report range
        for ($i = 0; $i < 30; $i++) {
            $audit->record(
                AuditEntry::make(
                    AuditEventCategory::Transaction,
                    'wallet.transfer',
                    AuditActorType::Customer,
                    'create',
                    AuditOutcome::Success,
                    (string) Uuid::uuid7(),
                )
                ->withActor($actorId)
                ->withEntity('transaction', (string) Uuid::uuid7())
                ->withOccurredAt($start->addDays($i))
            );
        }

        $reportId = (string) Uuid::uuid7();
        $report   = new ComplianceReportModel([
            'id'                    => $reportId,
            'report_type'           => 'compliance_summary',
            'status'                => 'pending',
            'requested_by_actor_id' => $actorId,
            'date_range_start'      => $start->toDateString(),
            'date_range_end'        => CarbonImmutable::now()->toDateString(),
            'idempotency_key'       => (string) Uuid::uuid7(),
        ]);
        $report->save();

        $wallStart = microtime(true);
        Bus::dispatchSync(new GenerateComplianceReportJob($reportId, (string) Uuid::uuid7()));
        $elapsed = microtime(true) - $wallStart;

        $report->refresh();

        $this->assertSame('completed', $report->status);
        $this->assertNotNull($report->report_data);
        $this->assertArrayHasKey('event_counts_by_category', $report->report_data);
        $this->assertSame(30, $report->report_data['event_counts_by_category']['transaction'] ?? 0);
        $this->assertLessThan(60.0, $elapsed, 'SC-005: GenerateComplianceReportJob must complete within 60s');
    }
}
