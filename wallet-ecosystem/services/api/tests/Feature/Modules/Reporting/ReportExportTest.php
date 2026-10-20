<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Reporting;

use App\Modules\Reporting\Domain\Enums\ReportJobStatus;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateExcelExportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateReportExportJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ReportExportTest extends ReportingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('reports');
    }

    /** @test */
    public function request_csv_export_for_complete_job_returns_202(): void
    {
        Bus::fake();
        $user  = $this->makeActorWithPermission('ADMIN', 'admin.read');
        $jobId = $this->seedActiveJob((string) $user->id);

        DB::connection('platform')
            ->table('reporting.report_jobs')
            ->where('id', $jobId)
            ->update(['status' => ReportJobStatus::Complete->value, 'result_data' => json_encode(['wallets' => []]), 'row_count' => 0]);

        $response = $this->withActorToken($user)->postJson("/v1/reports/jobs/{$jobId}/exports", [
            'format' => 'csv',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('status', 'pending');

        Bus::assertDispatched(GenerateReportExportJob::class);
    }

    /** @test */
    public function request_export_for_processing_job_returns_409(): void
    {
        Bus::fake();
        $user  = $this->makeActorWithPermission('ADMIN', 'admin.read');
        $jobId = $this->seedActiveJob((string) $user->id);

        DB::connection('platform')
            ->table('reporting.report_jobs')
            ->where('id', $jobId)
            ->update(['status' => ReportJobStatus::Processing->value]);

        $response = $this->withActorToken($user)->postJson("/v1/reports/jobs/{$jobId}/exports", [
            'format' => 'csv',
        ]);

        $response->assertStatus(409);
    }

    /** @test */
    public function dispatched_csv_export_job_sets_status_ready_with_download_url(): void
    {
        $user  = $this->makeActorWithPermission('ADMIN', 'admin.read');
        $jobId = $this->seedActiveJob((string) $user->id);

        DB::connection('platform')
            ->table('reporting.report_jobs')
            ->where('id', $jobId)
            ->update([
                'status'      => ReportJobStatus::Complete->value,
                'result_data' => json_encode(['wallets' => [['wallet_id' => 'w1', 'currency' => 'KES', 'balance' => '100.00', 'status' => 'active']]]),
                'row_count'   => 1,
            ]);

        $submitExport = $this->withActorToken($user)->postJson("/v1/reports/jobs/{$jobId}/exports", [
            'format' => 'csv',
        ]);
        $exportId = $submitExport->json('id');

        GenerateReportExportJob::dispatchSync($exportId, 'corr-export-test');

        $response = $this->withActorToken($user)->getJson("/v1/reports/exports/{$exportId}");
        $response->assertStatus(200)
            ->assertJsonPath('status', 'ready');

        $this->assertNotNull($response->json('download_url'));
    }

    /** @test */
    public function get_export_with_expired_token_returns_null_download_url(): void
    {
        $user  = $this->makeActorWithPermission('ADMIN', 'admin.read');
        $jobId = $this->seedActiveJob((string) $user->id);

        DB::connection('platform')
            ->table('reporting.report_jobs')
            ->where('id', $jobId)
            ->update(['status' => ReportJobStatus::Complete->value, 'result_data' => json_encode([]), 'row_count' => 0]);

        $submitExport = $this->withActorToken($user)->postJson("/v1/reports/jobs/{$jobId}/exports", [
            'format' => 'csv',
        ]);
        $exportId = $submitExport->json('id');

        GenerateReportExportJob::dispatchSync($exportId, 'corr-expired-test');

        DB::connection('platform')
            ->table('reporting.report_exports')
            ->where('id', $exportId)
            ->update(['token_expires_at' => now()->subHour()]);

        $response = $this->withActorToken($user)->getJson("/v1/reports/exports/{$exportId}");
        $response->assertStatus(200)
            ->assertJsonPath('download_url', null);
    }

    /** @test */
    public function regenerate_link_as_admin_returns_new_download_url(): void
    {
        $admin = $this->makeActorWithPermission('ADMIN', 'admin.read', 'admin.write');
        $jobId = $this->seedActiveJob((string) $admin->id);

        DB::connection('platform')
            ->table('reporting.report_jobs')
            ->where('id', $jobId)
            ->update(['status' => ReportJobStatus::Complete->value, 'result_data' => json_encode([]), 'row_count' => 0]);

        $submitExport = $this->withActorToken($admin)->postJson("/v1/reports/jobs/{$jobId}/exports", [
            'format' => 'csv',
        ]);
        $exportId = $submitExport->json('id');

        GenerateReportExportJob::dispatchSync($exportId, 'corr-regen-test');

        DB::connection('platform')
            ->table('reporting.report_exports')
            ->where('id', $exportId)
            ->update(['token_expires_at' => now()->subHour()]);

        $response = $this->withActorToken($admin)->postJson("/v1/reports/exports/{$exportId}/regenerate-link");
        $response->assertStatus(200);

        $this->assertNotNull($response->json('download_url'));
    }

    /** @test */
    public function get_purged_export_returns_410(): void
    {
        $user  = $this->makeActorWithPermission('ADMIN', 'admin.read');
        $jobId = $this->seedActiveJob((string) $user->id);

        DB::connection('platform')
            ->table('reporting.report_jobs')
            ->where('id', $jobId)
            ->update(['status' => ReportJobStatus::Complete->value, 'result_data' => json_encode([]), 'row_count' => 0]);

        $submitExport = $this->withActorToken($user)->postJson("/v1/reports/jobs/{$jobId}/exports", [
            'format' => 'csv',
        ]);
        $exportId = $submitExport->json('id');

        GenerateReportExportJob::dispatchSync($exportId, 'corr-purge-test');

        DB::connection('platform')
            ->table('reporting.report_exports')
            ->where('id', $exportId)
            ->update(['purged_at' => now()]);

        $response = $this->withActorToken($user)->getJson("/v1/reports/exports/{$exportId}");
        $response->assertStatus(410)
            ->assertJsonPath('code', 'EXPORT_PURGED');
    }

    /** @test */
    public function regenerate_link_on_purged_export_returns_410(): void
    {
        $admin = $this->makeActorWithPermission('ADMIN', 'admin.read', 'admin.write');
        $jobId = $this->seedActiveJob((string) $admin->id);

        DB::connection('platform')
            ->table('reporting.report_jobs')
            ->where('id', $jobId)
            ->update(['status' => ReportJobStatus::Complete->value, 'result_data' => json_encode([]), 'row_count' => 0]);

        $submitExport = $this->withActorToken($admin)->postJson("/v1/reports/jobs/{$jobId}/exports", [
            'format' => 'csv',
        ]);
        $exportId = $submitExport->json('id');

        GenerateReportExportJob::dispatchSync($exportId, 'corr-purge-regen-test');

        DB::connection('platform')
            ->table('reporting.report_exports')
            ->where('id', $exportId)
            ->update(['purged_at' => now()]);

        $response = $this->withActorToken($admin)->postJson("/v1/reports/exports/{$exportId}/regenerate-link");
        $response->assertStatus(410);
    }

    /** @test */
    public function request_excel_export_dispatches_excel_job(): void
    {
        Bus::fake();
        $user  = $this->makeActorWithPermission('ADMIN', 'admin.read');
        $jobId = $this->seedActiveJob((string) $user->id);

        DB::connection('platform')
            ->table('reporting.report_jobs')
            ->where('id', $jobId)
            ->update(['status' => ReportJobStatus::Complete->value, 'result_data' => json_encode([]), 'row_count' => 0]);

        $response = $this->withActorToken($user)->postJson("/v1/reports/jobs/{$jobId}/exports", [
            'format' => 'excel',
        ]);

        $response->assertStatus(202);
        Bus::assertDispatched(GenerateExcelExportJob::class);
    }
}
