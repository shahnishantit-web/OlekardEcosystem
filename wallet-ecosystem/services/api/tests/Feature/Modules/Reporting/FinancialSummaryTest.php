<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Reporting;

use App\Modules\Reporting\Domain\Enums\ReportJobStatus;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateFinancialSummaryReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateRiskReportJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class FinancialSummaryTest extends ReportingTestCase
{
    /** @test */
    public function financial_summary_report_requires_finance_reports_permission(): void
    {
        Bus::fake();
        $user = $this->makeActorWithPermission('ADMIN', 'admin.read');

        $response = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'financial-summary',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $response->assertStatus(403);
        Bus::assertNotDispatched(GenerateFinancialSummaryReportJob::class);
    }

    /** @test */
    public function financial_summary_completes_with_correct_structure(): void
    {
        $user = $this->makeActorWithPermission('ADMIN', 'finance.reports');

        $submit = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'financial-summary',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);
        $submit->assertStatus(202);
        $jobId = $submit->json('id');

        GenerateFinancialSummaryReportJob::dispatchSync($jobId, 'corr-financial-test');

        $response = $this->withActorToken($user)->getJson("/v1/reports/jobs/{$jobId}");
        $response->assertStatus(200)
            ->assertJsonPath('status', ReportJobStatus::Complete->value)
            ->assertJsonStructure(['id', 'report_type', 'status', 'row_count', 'result_data']);

        $resultData = $response->json('result_data');
        $this->assertArrayHasKey('currencies', $resultData);
        $this->assertArrayHasKey('overall_balance_validates', $resultData);
        $this->assertIsBool($resultData['overall_balance_validates']);
    }

    /** @test */
    public function financial_summary_currencies_items_have_expected_keys(): void
    {
        $user = $this->makeActorWithPermission('ADMIN', 'finance.reports');

        $submit = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'financial-summary',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);
        $jobId = $submit->json('id');

        GenerateFinancialSummaryReportJob::dispatchSync($jobId, 'corr-keys-test');

        $response   = $this->withActorToken($user)->getJson("/v1/reports/jobs/{$jobId}");
        $currencies = $response->json('result_data.currencies');

        if (!empty($currencies)) {
            $first = $currencies[0];
            $this->assertArrayHasKey('currency', $first);
            $this->assertArrayHasKey('total_inflow', $first);
            $this->assertArrayHasKey('total_outflow', $first);
            $this->assertArrayHasKey('total_fees', $first);
            $this->assertArrayHasKey('net_position', $first);
            $this->assertArrayHasKey('balance_validates', $first);
            $this->assertIsBool($first['balance_validates']);
        }

        $response->assertJsonPath('status', ReportJobStatus::Complete->value);
    }

    /** @test */
    public function financial_summary_does_not_fail_job_when_balance_mismatch(): void
    {
        $user = $this->makeActorWithPermission('ADMIN', 'finance.reports');

        $submit = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'financial-summary',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);
        $jobId = $submit->json('id');

        Log::spy();

        GenerateFinancialSummaryReportJob::dispatchSync($jobId, 'corr-no-fail-test');

        $response = $this->withActorToken($user)->getJson("/v1/reports/jobs/{$jobId}");
        $response->assertStatus(200)
            ->assertJsonPath('status', ReportJobStatus::Complete->value);
    }

    /** @test */
    public function risk_report_requires_compliance_reports_permission(): void
    {
        Bus::fake();
        $user = $this->makeActorWithPermission('ADMIN', 'admin.read');

        $response = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'risk-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $response->assertStatus(403);
        Bus::assertNotDispatched(GenerateRiskReportJob::class);
    }

    /** @test */
    public function risk_report_completes_with_correct_structure(): void
    {
        $user = $this->makeActorWithPermission('ADMIN', 'compliance.reports');

        $submit = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'risk-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);
        $submit->assertStatus(202);
        $jobId = $submit->json('id');

        GenerateRiskReportJob::dispatchSync($jobId, 'corr-risk-test');

        $response = $this->withActorToken($user)->getJson("/v1/reports/jobs/{$jobId}");
        $response->assertStatus(200)
            ->assertJsonPath('status', ReportJobStatus::Complete->value);

        $resultData = $response->json('result_data');
        $this->assertArrayHasKey('flag_count_by_category', $resultData);
        $this->assertArrayHasKey('velocity_breach_count', $resultData);
        $this->assertArrayHasKey('users_with_active_restrictions', $resultData);
        $this->assertArrayHasKey('top_flagged_users', $resultData);
        $this->assertIsArray($resultData['top_flagged_users']);
    }

    /** @test */
    public function risk_report_hashes_user_ids_when_pii_access_false(): void
    {
        $user   = $this->makeActorWithPermission('ADMIN', 'compliance.reports');
        $userId = (string) Uuid::uuid7();

        DB::connection('platform')->table('risk.aml_flags')->insertOrIgnore([
            'id'            => (string) Uuid::uuid7(),
            'user_id'       => $userId,
            'flag_category' => 'suspicious_activity',
            'status'        => 'open',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $submit = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'risk-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);
        $jobId = $submit->json('id');

        DB::connection('platform')
            ->table('reporting.report_jobs')
            ->where('id', $jobId)
            ->update(['pii_access' => false]);

        GenerateRiskReportJob::dispatchSync($jobId, 'corr-risk-pii-test');

        $response = $this->withActorToken($user)->getJson("/v1/reports/jobs/{$jobId}");
        $response->assertStatus(200);

        $topFlagged = $response->json('result_data.top_flagged_users') ?? [];
        foreach ($topFlagged as $entry) {
            $this->assertNotEquals($userId, $entry['identifier']);
            $this->assertSame(12, strlen((string) $entry['identifier']));
        }
    }
}
