<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Reporting;

use App\Modules\Reporting\Domain\Enums\ReportJobStatus;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateWalletReportJob;
use Illuminate\Support\Facades\Bus;

final class ReportJobTest extends ReportingTestCase
{
    /** @test */
    public function submit_wallet_report_with_admin_read_returns_202(): void
    {
        Bus::fake();
        $user = $this->makeActorWithPermission('ADMIN', 'admin.read');

        $response = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'wallet-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('status', ReportJobStatus::Requested->value);

        Bus::assertDispatched(GenerateWalletReportJob::class);
    }

    /** @test */
    public function submitted_job_transitions_to_complete_after_dispatch_now(): void
    {
        $user   = $this->makeActorWithPermission('ADMIN', 'admin.read');
        $submit = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'wallet-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $submit->assertStatus(202);
        $jobId = $submit->json('id');

        Bus::dispatchNow(GenerateWalletReportJob::dispatch($jobId, 'corr-test'));

        $response = $this->withActorToken($user)->getJson("/v1/reports/jobs/{$jobId}");
        $response->assertStatus(200)
            ->assertJsonPath('status', ReportJobStatus::Complete->value)
            ->assertJsonPath('as_of_at', fn ($v) => $v !== null)
            ->assertJsonPath('result_data', fn ($v) => $v !== null);
    }

    /** @test */
    public function fourth_concurrent_job_submission_returns_429(): void
    {
        $user = $this->makeActorWithPermission('ADMIN', 'admin.read');

        $this->seedActiveJob($user->id);
        $this->seedActiveJob($user->id);
        $this->seedActiveJob($user->id);

        $response = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'wallet-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $response->assertStatus(429)
            ->assertJsonPath('code', 'CONCURRENT_JOB_LIMIT_EXCEEDED');
    }

    /** @test */
    public function cancel_requested_job_returns_204(): void
    {
        $user  = $this->makeActorWithPermission('ADMIN', 'admin.read');
        $jobId = $this->seedActiveJob($user->id);

        $response = $this->withActorToken($user)->deleteJson("/v1/reports/jobs/{$jobId}");

        $response->assertStatus(204);
    }

    /** @test */
    public function cancel_complete_job_returns_409(): void
    {
        $user = $this->makeActorWithPermission('ADMIN', 'admin.read');

        $submitResp = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'wallet-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);
        $jobId = $submitResp->json('id');

        \Illuminate\Support\Facades\DB::connection('platform')
            ->table('reporting.report_jobs')
            ->where('id', $jobId)
            ->update(['status' => ReportJobStatus::Complete->value, 'completed_at' => now()]);

        $response = $this->withActorToken($user)->deleteJson("/v1/reports/jobs/{$jobId}");
        $response->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_JOB_CANNOT_CANCEL');
    }

    /** @test */
    public function unauthenticated_submit_returns_401(): void
    {
        $response = $this->postJson('/v1/reports/jobs', [
            'report_type' => 'wallet-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function submit_reconciliation_report_without_finance_reports_permission_returns_403(): void
    {
        $user = $this->makeActorWithPermission('ADMIN', 'admin.read');

        $response = $this->withActorToken($user)->postJson('/v1/reports/jobs', [
            'report_type' => 'reconciliation-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function get_job_for_another_users_job_returns_404(): void
    {
        $owner = $this->makeActorWithPermission('ADMIN', 'admin.read');
        $other = $this->makeActorWithPermission('ADMIN', 'admin.read');
        $jobId = $this->seedActiveJob($owner->id);

        $response = $this->withActorToken($other)->getJson("/v1/reports/jobs/{$jobId}");
        $response->assertStatus(404);
    }
}
