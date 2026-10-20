<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Reporting;

use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Reporting\Application\Services\ReportJobApplicationService;
use App\Modules\Reporting\Domain\Enums\ReportJobStatus;
use App\Modules\Reporting\Domain\Enums\ReportType;
use App\Modules\Reporting\Domain\Exceptions\ConcurrentJobLimitExceededException;
use App\Modules\Reporting\Domain\Exceptions\ReportJobCancelException;
use App\Modules\Reporting\Domain\Exceptions\RowLimitExceededException;
use App\Modules\Reporting\Infrastructure\Models\ReportDefinitionModel;
use App\Modules\Reporting\Infrastructure\Models\ReportJobModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class ReportJobApplicationServiceTest extends TestCase
{
    private AuditServiceInterface&MockInterface $audit;
    private OutboxEventPublisher&MockInterface  $outbox;
    private ReportJobApplicationService         $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->audit   = Mockery::mock(AuditServiceInterface::class);
        $this->outbox  = Mockery::mock(OutboxEventPublisher::class);
        $this->service = new ReportJobApplicationService($this->audit, $this->outbox);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function submit_creates_job_with_requested_status(): void
    {
        $userId        = 'user-uuid-1';
        $correlationId = 'corr-uuid-1';
        $type          = ReportType::WalletReport;

        $definition = Mockery::mock(ReportDefinitionModel::class);
        $definition->shouldReceive('getAttribute')->with('id')->andReturn('def-uuid-1');
        $definition->shouldReceive('getAttribute')->with('filter_schema')->andReturn([
            'type'       => 'object',
            'required'   => ['date_range_start', 'date_range_end'],
            'properties' => [],
        ]);

        $this->mockDefinitionQuery($type, $definition);
        $this->mockEstimateCount(50);
        $this->mockTransaction($userId, 0);

        $this->outbox->shouldReceive('publish')->once();
        $this->audit->shouldReceive('record')->once();

        $job = $this->service->submit(
            userId:        $userId,
            type:          $type,
            filters:       ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
            piiAccess:     false,
            correlationId: $correlationId,
        );

        $this->assertSame(ReportJobStatus::Requested, $job->status);
    }

    /** @test */
    public function submit_throws_concurrent_limit_when_three_active_jobs(): void
    {
        $this->expectException(ConcurrentJobLimitExceededException::class);

        $userId = 'user-uuid-1';
        $type   = ReportType::WalletReport;

        $definition = Mockery::mock(ReportDefinitionModel::class);
        $definition->shouldReceive('getAttribute')->with('id')->andReturn('def-uuid-1');
        $definition->shouldReceive('getAttribute')->with('filter_schema')->andReturn([
            'type'       => 'object',
            'required'   => ['date_range_start', 'date_range_end'],
            'properties' => [],
        ]);

        $this->mockDefinitionQuery($type, $definition);
        $this->mockEstimateCount(50);
        $this->mockTransaction($userId, 3);

        $this->service->submit(
            userId:        $userId,
            type:          $type,
            filters:       ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
            piiAccess:     false,
            correlationId: 'corr-uuid-1',
        );
    }

    /** @test */
    public function cancel_transitions_requested_job_to_failed(): void
    {
        $job = Mockery::mock(ReportJobModel::class);
        $job->shouldReceive('getAttribute')->with('status')->andReturn(ReportJobStatus::Requested);
        $job->shouldReceive('getAttribute')->with('id')->andReturn('job-uuid-1');
        $job->shouldReceive('setAttribute')->withAnyArgs()->andReturnNull();
        $job->shouldReceive('save')->once()->andReturnTrue();

        DB::shouldReceive('connection->transaction')->andReturnUsing(
            static fn (callable $callback) => $callback()
        );

        $this->audit->shouldReceive('record')->once();

        $this->service->cancel('job-uuid-1', 'user-uuid-1', 'corr-uuid-1');
    }

    /** @test */
    public function cancel_throws_when_job_is_processing(): void
    {
        $this->expectException(ReportJobCancelException::class);

        $job = Mockery::mock(ReportJobModel::class);
        $job->shouldReceive('getAttribute')->with('status')->andReturn(ReportJobStatus::Processing);
        $job->shouldReceive('getAttribute')->with('id')->andReturn('job-uuid-1');

        $this->mockGetJob('job-uuid-1', 'user-uuid-1', $job);

        $this->service->cancel('job-uuid-1', 'user-uuid-1', 'corr-uuid-1');
    }

    /** @test */
    public function submit_throws_validation_exception_when_required_filter_missing(): void
    {
        $this->expectException(ValidationException::class);

        $type = ReportType::WalletReport;

        $definition = Mockery::mock(ReportDefinitionModel::class);
        $definition->shouldReceive('getAttribute')->with('id')->andReturn('def-uuid-1');
        $definition->shouldReceive('getAttribute')->with('filter_schema')->andReturn([
            'type'       => 'object',
            'required'   => ['date_range_start', 'date_range_end'],
            'properties' => [],
        ]);

        $this->mockDefinitionQuery($type, $definition);

        $this->service->submit(
            userId:        'user-uuid-1',
            type:          $type,
            filters:       ['date_range_end' => '2026-12-31'],
            piiAccess:     false,
            correlationId: 'corr-uuid-1',
        );
    }

    /** @test */
    public function submit_throws_row_limit_exceeded_when_count_exceeds_max(): void
    {
        $this->expectException(RowLimitExceededException::class);

        $type = ReportType::WalletReport;

        $definition = Mockery::mock(ReportDefinitionModel::class);
        $definition->shouldReceive('getAttribute')->with('id')->andReturn('def-uuid-1');
        $definition->shouldReceive('getAttribute')->with('filter_schema')->andReturn([
            'type'       => 'object',
            'required'   => ['date_range_start', 'date_range_end'],
            'properties' => [],
        ]);

        $this->mockDefinitionQuery($type, $definition);
        $this->mockEstimateCount(100001);

        config(['reports.max_rows_default' => 100000]);

        $this->service->submit(
            userId:        'user-uuid-1',
            type:          $type,
            filters:       ['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31'],
            piiAccess:     false,
            correlationId: 'corr-uuid-1',
        );
    }

    private function mockDefinitionQuery(ReportType $type, ReportDefinitionModel $definition): void
    {
        ReportDefinitionModel::shouldReceive('active->where->firstOrFail')
            ->andReturn($definition);
    }

    private function mockEstimateCount(int $count): void
    {
        DB::shouldReceive('connection->table->where->where->count')
            ->andReturn($count);
        DB::shouldReceive('connection->table->where->count')
            ->andReturn($count);
        DB::shouldReceive('connection->table->count')
            ->andReturn($count);
    }

    private function mockTransaction(string $userId, int $activeJobCount): void
    {
        DB::shouldReceive('connection->transaction')->andReturnUsing(
            function (callable $callback) use ($userId, $activeJobCount) {
                ReportJobModel::shouldReceive('query->where->whereIn->lockForUpdate->count')
                    ->andReturn($activeJobCount);

                if ($activeJobCount >= 3) {
                    return $callback();
                }

                $job = new ReportJobModel();
                $job->status = ReportJobStatus::Requested;
                ReportJobModel::shouldReceive('save')->andReturnTrue();

                return $callback();
            }
        );
    }

    private function mockGetJob(string $jobId, string $userId, ReportJobModel $job): void
    {
        ReportJobModel::shouldReceive('query->where->where->first')
            ->andReturn($job);
    }
}
