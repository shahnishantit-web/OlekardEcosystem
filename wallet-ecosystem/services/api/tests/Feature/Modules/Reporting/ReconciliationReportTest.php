<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Reporting;

use App\Modules\Reporting\Domain\Enums\ReportJobStatus;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateReconciliationReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateSettlementReportJob;
use Illuminate\Support\Facades\Bus;

final class ReconciliationReportTest extends ReportingTestCase
{
    /** @test */
    public function submit_reconciliation_report_with_finance_reports_returns_202(): void
    {
        Bus::fake();
        $user = $this->makeActorWithPermission('ADMIN', 'finance.reports');

        $response = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'reconciliation-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $response->assertStatus(202);
        Bus::assertDispatched(GenerateReconciliationReportJob::class);
    }

    /** @test */
    public function submit_reconciliation_report_without_finance_reports_returns_403(): void
    {
        $user = $this->makeActorWithPermission('ADMIN', 'admin.read');

        $response = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'reconciliation-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function submit_settlement_report_with_finance_reports_returns_202(): void
    {
        Bus::fake();
        $user = $this->makeActorWithPermission('ADMIN', 'finance.reports');

        $this->ensureReportDefinition('settlement-report', 'finance.reports');

        $response = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'settlement-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $response->assertStatus(202);
        Bus::assertDispatched(GenerateSettlementReportJob::class);
    }

    /** @test */
    public function dispatched_reconciliation_job_completes_with_items_array(): void
    {
        $user   = $this->makeActorWithPermission('ADMIN', 'finance.reports');
        $submit = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'reconciliation-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $submit->assertStatus(202);
        $jobId = $submit->json('id');

        Bus::dispatchNow(GenerateReconciliationReportJob::dispatch($jobId, 'corr-test'));

        $response = $this->withActorToken($user)->getJson("/v1/reports/jobs/{$jobId}");
        $response->assertStatus(200)
            ->assertJsonPath('status', ReportJobStatus::Complete->value)
            ->assertJsonPath('result_data', fn ($v) => is_array($v) && array_key_exists('items', $v))
            ->assertJsonPath('as_of_at', fn ($v) => $v !== null);
    }

    /** @test */
    public function reconciliation_result_rows_contain_reconciliation_status_field(): void
    {
        $user   = $this->makeActorWithPermission('ADMIN', 'finance.reports');
        $submit = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'reconciliation-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $jobId = $submit->json('id');
        Bus::dispatchNow(GenerateReconciliationReportJob::dispatch($jobId, 'corr-test'));

        $response = $this->withActorToken($user)->getJson("/v1/reports/jobs/{$jobId}");

        $items = $response->json('result_data.items');
        $this->assertIsArray($items);

        foreach ($items as $item) {
            $this->assertArrayHasKey('reconciliation_status', $item);
        }
    }
}
