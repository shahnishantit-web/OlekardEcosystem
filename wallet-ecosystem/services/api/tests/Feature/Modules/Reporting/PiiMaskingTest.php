<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Reporting;

use App\Modules\Reporting\Domain\Enums\ReportJobStatus;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateAgentReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateKycReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateUserReportJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

final class PiiMaskingTest extends ReportingTestCase
{
    /** @test */
    public function user_report_without_pii_access_masks_email(): void
    {
        $user = $this->makeActorWithPermission('ADMIN', 'admin.read');

        $submit = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'user-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $jobId = $submit->json('id');

        DB::connection('platform')
            ->table('reporting.report_jobs')
            ->where('id', $jobId)
            ->update(['pii_access' => false]);

        Bus::dispatchNow(GenerateUserReportJob::dispatch($jobId, 'corr-test'));

        $response = $this->withActorToken($user)->getJson("/v1/reports/jobs/{$jobId}");
        $response->assertStatus(200);

        $users = $response->json('result_data.users') ?? [];
        foreach ($users as $u) {
            $this->assertSame('***', $u['email'] ?? null);
        }
    }

    /** @test */
    public function user_report_with_pii_access_exposes_email(): void
    {
        $user = $this->makeActorWithPermission('ADMIN', 'admin.read', 'compliance.pii.read');

        $submit = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'user-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $jobId = $submit->json('id');

        DB::connection('platform')
            ->table('reporting.report_jobs')
            ->where('id', $jobId)
            ->update(['pii_access' => true]);

        Bus::dispatchNow(GenerateUserReportJob::dispatch($jobId, 'corr-test'));

        $response = $this->withActorToken($user)->getJson("/v1/reports/jobs/{$jobId}");
        $response->assertStatus(200)
            ->assertJsonPath('status', ReportJobStatus::Complete->value);
    }

    /** @test */
    public function kyc_report_without_pii_access_excludes_national_id(): void
    {
        $user = $this->makeActorWithPermission('ADMIN', 'compliance.reports');

        $this->ensureReportDefinition('kyc-report', 'compliance.reports', true);

        $submit = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'kyc-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $jobId = $submit->json('id');

        DB::connection('platform')
            ->table('reporting.report_jobs')
            ->where('id', $jobId)
            ->update(['pii_access' => false]);

        Bus::dispatchNow(GenerateKycReportJob::dispatch($jobId, 'corr-test'));

        $response = $this->withActorToken($user)->getJson("/v1/reports/jobs/{$jobId}");
        $response->assertStatus(200);

        $verifications = $response->json('result_data.verifications') ?? [];
        foreach ($verifications as $v) {
            $this->assertArrayNotHasKey('national_id_number', $v);
        }
    }

    /** @test */
    public function kyc_report_with_pii_access_returns_national_id(): void
    {
        $user = $this->makeActorWithPermission('ADMIN', 'compliance.reports', 'compliance.pii.read');

        $this->ensureReportDefinition('kyc-report', 'compliance.reports', true);

        $submit = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'kyc-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $jobId = $submit->json('id');

        DB::connection('platform')
            ->table('reporting.report_jobs')
            ->where('id', $jobId)
            ->update(['pii_access' => true]);

        Bus::dispatchNow(GenerateKycReportJob::dispatch($jobId, 'corr-test'));

        $response = $this->withActorToken($user)->getJson("/v1/reports/jobs/{$jobId}");
        $response->assertStatus(200)
            ->assertJsonPath('status', ReportJobStatus::Complete->value);
    }

    /** @test */
    public function agent_report_with_admin_read_completes_without_error(): void
    {
        Bus::fake();
        $user = $this->makeActorWithPermission('ADMIN', 'admin.read');

        $this->ensureReportDefinition('agent-report', 'admin.read');

        $response = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'agent-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $response->assertStatus(202);
        Bus::assertDispatched(GenerateAgentReportJob::class);
    }
}
