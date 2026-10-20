<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Reporting;

use App\Modules\Reporting\Domain\Enums\ReportJobStatus;
use App\Modules\Reporting\Infrastructure\Console\Commands\ProcessReportSchedulesCommand;
use App\Modules\Reporting\Infrastructure\Models\ReportJobModel;
use App\Modules\Reporting\Infrastructure\Models\ReportScheduleModel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

final class ReportScheduleTest extends ReportingTestCase
{
    /** @test */
    public function create_daily_schedule_returns_201_with_correct_structure(): void
    {
        $user = $this->makeActorWithPermission('ADMIN', 'reports.schedule');

        $response = $this->withActorToken($user)->postJson('/v1/reports/schedules', [
            'name'        => 'Daily Wallet Report',
            'report_type' => 'wallet-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
            'cadence'     => 'daily',
            'cadence_time' => '06:00',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'active')
            ->assertJsonStructure(['id', 'name', 'report_type', 'cadence', 'next_run_at', 'status']);

        $this->assertNotNull($response->json('next_run_at'));
        $this->assertGreaterThan(now()->timestamp, strtotime($response->json('next_run_at')));
    }

    /** @test */
    public function process_schedules_command_dispatches_job_for_due_schedule(): void
    {
        Bus::fake();
        $user = $this->makeActorWithPermission('ADMIN', 'reports.schedule');

        $response = $this->withActorToken($user)->postJson('/v1/reports/schedules', [
            'name'        => 'Test Schedule',
            'report_type' => 'wallet-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
            'cadence'     => 'daily',
            'cadence_time' => '06:00',
        ]);
        $scheduleId = $response->json('id');

        DB::connection('platform')
            ->table('reporting.report_schedules')
            ->where('id', $scheduleId)
            ->update(['next_run_at' => now()->subMinute()]);

        Artisan::call('reports:process-schedules');

        $jobCount = DB::connection('platform')
            ->table('reporting.report_jobs')
            ->where('requested_by_user_id', (string) $user->id)
            ->where('report_type', 'wallet-report')
            ->count();

        $this->assertGreaterThan(0, $jobCount);

        $updatedSchedule = DB::connection('platform')
            ->table('reporting.report_schedules')
            ->where('id', $scheduleId)
            ->first();

        $this->assertGreaterThan(now()->timestamp, strtotime($updatedSchedule->next_run_at));
    }

    /** @test */
    public function process_schedules_does_not_duplicate_on_second_run(): void
    {
        Bus::fake();
        $user = $this->makeActorWithPermission('ADMIN', 'reports.schedule');

        $response = $this->withActorToken($user)->postJson('/v1/reports/schedules', [
            'name'        => 'No-Duplicate Schedule',
            'report_type' => 'wallet-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
            'cadence'     => 'daily',
            'cadence_time' => '06:00',
        ]);
        $scheduleId = $response->json('id');

        DB::connection('platform')
            ->table('reporting.report_schedules')
            ->where('id', $scheduleId)
            ->update(['next_run_at' => now()->subMinute()]);

        Artisan::call('reports:process-schedules');

        $countAfterFirst = DB::connection('platform')
            ->table('reporting.report_jobs')
            ->where('requested_by_user_id', (string) $user->id)
            ->count();

        Artisan::call('reports:process-schedules');

        $countAfterSecond = DB::connection('platform')
            ->table('reporting.report_jobs')
            ->where('requested_by_user_id', (string) $user->id)
            ->count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    /** @test */
    public function deactivated_schedule_is_not_triggered_by_process_command(): void
    {
        Bus::fake();
        $user = $this->makeActorWithPermission('ADMIN', 'reports.schedule');

        $response = $this->withActorToken($user)->postJson('/v1/reports/schedules', [
            'name'        => 'Inactive Schedule',
            'report_type' => 'wallet-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
            'cadence'     => 'daily',
            'cadence_time' => '06:00',
        ]);
        $scheduleId = $response->json('id');

        $this->withActorToken($user)->deleteJson("/v1/reports/schedules/{$scheduleId}");

        DB::connection('platform')
            ->table('reporting.report_schedules')
            ->where('id', $scheduleId)
            ->update(['next_run_at' => now()->subMinute()]);

        $jobsBefore = DB::connection('platform')->table('reporting.report_jobs')->count();
        Artisan::call('reports:process-schedules');
        $jobsAfter = DB::connection('platform')->table('reporting.report_jobs')->count();

        $this->assertSame($jobsBefore, $jobsAfter);
    }

    /** @test */
    public function list_runs_returns_run_records_after_dispatch(): void
    {
        Bus::fake();
        $user = $this->makeActorWithPermission('ADMIN', 'reports.schedule');

        $response = $this->withActorToken($user)->postJson('/v1/reports/schedules', [
            'name'        => 'Schedule With Runs',
            'report_type' => 'wallet-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
            'cadence'     => 'daily',
            'cadence_time' => '06:00',
        ]);
        $scheduleId = $response->json('id');

        DB::connection('platform')
            ->table('reporting.report_schedules')
            ->where('id', $scheduleId)
            ->update(['next_run_at' => now()->subMinute()]);

        Artisan::call('reports:process-schedules');

        $runsResponse = $this->withActorToken($user)->getJson("/v1/reports/schedules/{$scheduleId}/runs");
        $runsResponse->assertStatus(200);

        $data = $runsResponse->json('data');
        $this->assertNotEmpty($data);
        $this->assertSame('dispatched', $data[0]['outcome']);
    }

    /** @test */
    public function create_schedule_without_permission_returns_403(): void
    {
        $user = $this->makeActorWithPermission('ADMIN', 'admin.read');

        $response = $this->withActorToken($user)->postJson('/v1/reports/schedules', [
            'name'        => 'Unauthorized Schedule',
            'report_type' => 'wallet-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
            'cadence'     => 'daily',
            'cadence_time' => '06:00',
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function dry_run_flag_outputs_schedule_without_creating_jobs(): void
    {
        Bus::fake();
        $user = $this->makeActorWithPermission('ADMIN', 'reports.schedule');

        $response = $this->withActorToken($user)->postJson('/v1/reports/schedules', [
            'name'        => 'Dry Run Schedule',
            'report_type' => 'wallet-report',
            'filters'     => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
            'cadence'     => 'daily',
            'cadence_time' => '06:00',
        ]);
        $scheduleId = $response->json('id');

        DB::connection('platform')
            ->table('reporting.report_schedules')
            ->where('id', $scheduleId)
            ->update(['next_run_at' => now()->subMinute()]);

        $jobsBefore = DB::connection('platform')->table('reporting.report_jobs')->count();
        Artisan::call('reports:process-schedules', ['--dry-run' => true]);
        $jobsAfter = DB::connection('platform')->table('reporting.report_jobs')->count();

        $this->assertSame($jobsBefore, $jobsAfter);
    }

    /** @test */
    public function weekly_schedule_advances_next_run_at_by_seven_days(): void
    {
        Bus::fake();
        $user = $this->makeActorWithPermission('ADMIN', 'reports.schedule');

        $response = $this->withActorToken($user)->postJson('/v1/reports/schedules', [
            'name'               => 'Weekly Schedule',
            'report_type'        => 'wallet-report',
            'filters'            => ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
            'cadence'            => 'weekly',
            'cadence_time'       => '06:00',
            'cadence_day_of_week' => 1,
        ]);
        $scheduleId = $response->json('id');

        $triggerTime = now()->subMinute();
        DB::connection('platform')
            ->table('reporting.report_schedules')
            ->where('id', $scheduleId)
            ->update(['next_run_at' => $triggerTime]);

        Artisan::call('reports:process-schedules');

        $updatedSchedule = DB::connection('platform')
            ->table('reporting.report_schedules')
            ->where('id', $scheduleId)
            ->first();

        $diff = strtotime($updatedSchedule->next_run_at) - $triggerTime->timestamp;
        $this->assertGreaterThanOrEqual(6 * 24 * 3600, $diff);
    }
}
