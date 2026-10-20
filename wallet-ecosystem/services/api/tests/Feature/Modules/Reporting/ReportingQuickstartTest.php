<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Reporting;

use App\Modules\Reporting\Domain\Enums\ReportJobStatus;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateReportExportJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;

/**
 * @group docker
 *
 * E24 Reporting & Analytics — Quickstart SC-1 through SC-9.
 * Validates the acceptance scenarios from specs/024-reporting-analytics/quickstart.md.
 */
final class ReportingQuickstartTest extends ReportingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureReportDefinition('commission-report', 'finance.reports');
        $this->ensureReportDefinition('settlement-report', 'finance.reports');
        Storage::fake('reports');
    }

    // ── SC-1: Report catalogue ────────────────────────────────────────────────

    /** SC-1a: Catalogue endpoint returns 200 with a list of report definitions. */
    public function test_sc1_catalogue_returns_200_with_definitions(): void
    {
        $user = $this->makeActorWithPermission('ADMIN', 'admin.read');

        $response = $this->withActorToken($user)->getJson('/v1/reports/catalogue');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => [['id', 'type', 'name', 'required_permission']]]);
    }

    /** SC-1b: Catalogue includes all 10 expected report types (user has all required permissions). */
    public function test_sc1_catalogue_includes_all_ten_report_types(): void
    {
        $user = $this->makeActorWithPermission(
            'ADMIN',
            'admin.read', 'finance.reports', 'compliance.reports', 'reports.schedule',
        );

        $response = $this->withActorToken($user)->getJson('/v1/reports/catalogue');

        $response->assertStatus(200);
        $types = collect($response->json('data'))->pluck('type')->toArray();

        $expected = [
            'user-report', 'wallet-report', 'transaction-report', 'agent-report',
            'commission-report', 'kyc-report', 'reconciliation-report',
            'settlement-report', 'risk-report', 'financial-summary',
        ];
        foreach ($expected as $type) {
            $this->assertContains($type, $types, "Catalogue must include type: {$type}");
        }
    }

    // ── SC-2: Submit a report job and verify completion ───────────────────────

    /** SC-2a: Submit a job returns 202 with status=requested. */
    public function test_sc2_submit_job_returns_202_with_requested_status(): void
    {
        Bus::fake();
        $user = $this->makeActorWithPermission('ADMIN', 'admin.read');

        $response = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'transaction-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.status', ReportJobStatus::Requested->value);
        $this->assertNotEmpty($response->json('data.id'));
    }

    /** SC-2b: After processing, job transitions to complete with as_of_at set. */
    public function test_sc2_job_transitions_to_complete_with_as_of_timestamp(): void
    {
        // QUEUE_CONNECTION=sync: job runs during POST — no Bus::fake() needed
        $user   = $this->makeActorWithPermission('ADMIN', 'admin.read');
        $submit = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'wallet-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $submit->assertStatus(202);
        $jobId = $submit->json('data.id');

        $response = $this->withActorToken($user)->getJson("/v1/reports/jobs/{$jobId}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', ReportJobStatus::Complete->value);
        $this->assertNotNull($response->json('data.as_of_at'));
    }

    // ── SC-3: Export a completed job to CSV ───────────────────────────────────

    /** SC-3: Export request returns 202 with status=pending. */
    public function test_sc3_export_completed_job_returns_202_with_pending_status(): void
    {
        Bus::fake([GenerateReportExportJob::class]);
        $user  = $this->makeActorWithPermission('ADMIN', 'admin.read');
        $jobId = $this->seedActiveJob($user->id);

        DB::connection('platform')->table('reporting.report_jobs')
            ->where('id', $jobId)
            ->update(['status' => ReportJobStatus::Complete->value, 'result_data' => json_encode([]), 'row_count' => 0]);

        $response = $this->withActorToken($user)->postJson("/v1/reports/jobs/{$jobId}/exports", [
            'format' => 'csv',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.status', 'pending');
        $this->assertNotEmpty($response->json('data.id'));
    }

    // ── SC-4: Per-user concurrent job limit ───────────────────────────────────

    /** SC-4: 4th concurrent job submission returns 429. */
    public function test_sc4_fourth_concurrent_job_returns_429(): void
    {
        $user = $this->makeActorWithPermission('ADMIN', 'admin.read');

        $this->seedActiveJob($user->id);
        $this->seedActiveJob($user->id);
        $this->seedActiveJob($user->id);

        $response = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'wallet-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $response->assertStatus(429);
        $response->assertJsonPath('error.code', 'CONCURRENT_JOB_LIMIT_EXCEEDED');
    }

    // ── SC-5: Authorization enforcement ──────────────────────────────────────

    /** SC-5a: User without required permission gets 403 on restricted report type. */
    public function test_sc5_user_without_finance_reports_permission_gets_403(): void
    {
        $user = $this->makeActorWithPermission('ADMIN', 'admin.read');

        $response = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'reconciliation-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $response->assertStatus(403);
    }

    /** SC-5b: Unauthenticated request returns 401. */
    public function test_sc5_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/v1/reports/catalogue');
        $response->assertStatus(401);
    }

    // ── SC-6: Financial summary balance validation ────────────────────────────

    /** SC-6: Financial summary result_data has balance validation and currency breakdown. */
    public function test_sc6_financial_summary_result_has_balance_validation(): void
    {
        // QUEUE_CONNECTION=sync: job runs during POST — no Bus::fake() needed
        $user   = $this->makeActorWithPermission('ADMIN', 'finance.reports');
        $submit = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'financial-summary',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $submit->assertStatus(202);
        $jobId = $submit->json('data.id');

        $response = $this->withActorToken($user)->getJson("/v1/reports/jobs/{$jobId}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', ReportJobStatus::Complete->value);

        $resultData = $response->json('data.result_data');
        $this->assertArrayHasKey('overall_balance_validates', $resultData);
        $this->assertIsBool($resultData['overall_balance_validates']);
        $this->assertArrayHasKey('currencies', $resultData);
    }

    // ── SC-7: Report schedule create and deactivate ───────────────────────────

    /** SC-7a: Create a daily schedule returns 201 with active status. */
    public function test_sc7_create_schedule_returns_201_active(): void
    {
        $user = $this->makeActorWithPermission('ADMIN', 'reports.schedule');

        $response = $this->withActorToken($user)->postJson('/v1/reports/schedules', [
            'name'         => 'Daily Agent Report QS',
            'report_type'  => 'agent-report',
            'filters'      => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
            'cadence'      => 'daily',
            'cadence_time' => '06:00',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'active');
        $response->assertJsonStructure(['data' => ['id', 'name', 'report_type', 'cadence', 'next_run_at', 'status']]);
        $this->assertNotNull($response->json('data.next_run_at'));
    }

    /** SC-7b: Deactivating a schedule returns 204 and marks it inactive. */
    public function test_sc7_deactivate_schedule_returns_204_and_sets_inactive(): void
    {
        $user = $this->makeActorWithPermission('ADMIN', 'reports.schedule');

        $create = $this->withActorToken($user)->postJson('/v1/reports/schedules', [
            'name'         => 'Schedule to deactivate QS',
            'report_type'  => 'agent-report',
            'filters'      => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
            'cadence'      => 'daily',
            'cadence_time' => '08:00',
        ]);
        $create->assertStatus(201);
        $schedId = $create->json('data.id');

        $this->withActorToken($user)->deleteJson("/v1/reports/schedules/{$schedId}")->assertStatus(204);

        $this->withActorToken($user)->getJson("/v1/reports/schedules/{$schedId}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'inactive');
    }

    // ── SC-9: Export link expiry ──────────────────────────────────────────────

    /** SC-9a: Expired token makes download_url null in GET export response. */
    public function test_sc9_expired_link_returns_null_download_url(): void
    {
        $user  = $this->makeActorWithPermission('ADMIN', 'admin.read');
        $jobId = $this->seedActiveJob($user->id);

        DB::connection('platform')->table('reporting.report_jobs')
            ->where('id', $jobId)
            ->update(['status' => ReportJobStatus::Complete->value, 'result_data' => json_encode([]), 'row_count' => 0]);

        $exportId = (string) Uuid::uuid7();
        DB::connection('platform')->table('reporting.report_exports')->insert([
            'id'                        => $exportId,
            'job_id'                    => $jobId,
            'format'                    => 'csv',
            'status'                    => 'ready',
            'row_count'                 => 0,
            'download_token'            => (string) Uuid::uuid7(),
            'token_expires_at'          => now()->subHour(),
            'file_retention_expires_at' => now()->addDays(7),
            'created_by_user_id'        => $user->id,
            'created_at'                => now(),
        ]);

        $response = $this->withActorToken($user)->getJson("/v1/reports/exports/{$exportId}");

        $response->assertStatus(200);
        $this->assertNull($response->json('data.download_url'));
    }

    /** SC-9b: Regenerate-link returns a new non-null download URL. */
    public function test_sc9_regenerate_link_returns_new_download_url(): void
    {
        $user  = $this->makeActorWithPermission('ADMIN', 'admin.read', 'admin.write');
        $jobId = $this->seedActiveJob($user->id);

        DB::connection('platform')->table('reporting.report_jobs')
            ->where('id', $jobId)
            ->update(['status' => ReportJobStatus::Complete->value, 'result_data' => json_encode([]), 'row_count' => 0]);

        $exportId = (string) Uuid::uuid7();
        DB::connection('platform')->table('reporting.report_exports')->insert([
            'id'                        => $exportId,
            'job_id'                    => $jobId,
            'format'                    => 'csv',
            'status'                    => 'ready',
            'row_count'                 => 0,
            'download_token'            => (string) Uuid::uuid7(),
            'token_expires_at'          => now()->subHour(),
            'file_retention_expires_at' => now()->addDays(7),
            'created_by_user_id'        => $user->id,
            'created_at'                => now(),
        ]);

        $response = $this->withActorToken($user)->postJson("/v1/reports/exports/{$exportId}/regenerate-link");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.download_url'));
    }
}
