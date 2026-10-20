<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Reporting;

use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Reporting\Domain\Enums\ReportJobStatus;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateWalletReportJob;
use App\Modules\Reporting\Infrastructure\Models\ReportJobModel;
use Illuminate\Contracts\Queue\TimeoutExceeded;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class GenerateWalletReportJobTest extends TestCase
{
    private AuditServiceInterface&MockInterface $audit;
    private OutboxEventPublisher&MockInterface  $outbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->audit  = Mockery::mock(AuditServiceInterface::class);
        $this->outbox = Mockery::mock(OutboxEventPublisher::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function happy_path_transitions_to_complete_and_sets_result(): void
    {
        $job           = $this->buildMockJob();
        $correlationId = 'corr-uuid-1';

        ReportJobModel::shouldReceive('findOrFail')
            ->with($job->id)
            ->andReturn($job);

        DB::shouldReceive('connection->transaction')->times(3)->andReturnUsing(
            static fn (callable $cb) => $cb()
        );
        DB::shouldReceive('connection->table->where->where->count')->andReturn(2);
        DB::shouldReceive('connection->table->where->where->get')
            ->andReturn(collect([
                (object) ['id' => 'w1', 'user_id' => 'u1', 'currency_code' => 'BWP', 'balance' => 100, 'status' => 'active', 'created_at' => '2026-01-01'],
                (object) ['id' => 'w2', 'user_id' => 'u2', 'currency_code' => 'USD', 'balance' => 200, 'status' => 'active', 'created_at' => '2026-01-02'],
            ]));

        $job->shouldReceive('transitionTo')->with(ReportJobStatus::Processing)->once();
        $job->shouldReceive('transitionTo')->with(ReportJobStatus::Complete)->once();
        $job->shouldReceive('setAttribute')->withAnyArgs()->andReturnNull();
        $job->shouldReceive('save')->twice()->andReturnTrue();

        $this->audit->shouldReceive('record')->times(2);
        $this->outbox->shouldReceive('publish')->once();

        $handler = new GenerateWalletReportJob($job->id, $correlationId);
        $handler->handle($this->audit, $this->outbox);
    }

    /** @test */
    public function timeout_path_sets_failure_reason_execution_timeout(): void
    {
        $job           = $this->buildMockJob();
        $correlationId = 'corr-uuid-2';

        ReportJobModel::shouldReceive('findOrFail')
            ->with($job->id)
            ->andReturn($job);

        $timeout = Mockery::mock(TimeoutExceeded::class);

        DB::shouldReceive('connection->transaction')->andReturnUsing(
            static fn (callable $cb) => $cb()
        );
        DB::shouldReceive('connection->table->where->where->count')->andThrow($timeout);

        $job->shouldReceive('transitionTo')->with(ReportJobStatus::Processing)->once();
        $job->shouldReceive('transitionTo')->with(ReportJobStatus::Failed)->once();
        $job->shouldReceive('setAttribute')
            ->with('failure_reason', 'execution_timeout')
            ->once();
        $job->shouldReceive('setAttribute')->withAnyArgs()->andReturnNull();
        $job->shouldReceive('save')->twice()->andReturnTrue();

        $this->audit->shouldReceive('record')->times(2);
        $this->outbox->shouldReceive('publish')->once();

        $this->expectException(\get_class($timeout));

        $handler = new GenerateWalletReportJob($job->id, $correlationId);
        $handler->handle($this->audit, $this->outbox);
    }

    /** @test */
    public function generic_error_sets_failure_reason_to_message(): void
    {
        $job           = $this->buildMockJob();
        $correlationId = 'corr-uuid-3';

        ReportJobModel::shouldReceive('findOrFail')
            ->with($job->id)
            ->andReturn($job);

        DB::shouldReceive('connection->transaction')->andReturnUsing(
            static fn (callable $cb) => $cb()
        );
        DB::shouldReceive('connection->table->where->where->count')
            ->andThrow(new \RuntimeException('db error'));

        $job->shouldReceive('transitionTo')->with(ReportJobStatus::Processing)->once();
        $job->shouldReceive('transitionTo')->with(ReportJobStatus::Failed)->once();
        $job->shouldReceive('setAttribute')
            ->with('failure_reason', Mockery::on(fn ($v) => str_contains($v, 'db error') && !str_contains($v, 'execution_timeout')))
            ->once();
        $job->shouldReceive('setAttribute')->withAnyArgs()->andReturnNull();
        $job->shouldReceive('save')->twice()->andReturnTrue();

        $this->audit->shouldReceive('record')->times(2);
        $this->outbox->shouldReceive('publish')->once();

        $this->expectException(\RuntimeException::class);

        $handler = new GenerateWalletReportJob($job->id, $correlationId);
        $handler->handle($this->audit, $this->outbox);
    }

    /** @test */
    public function three_audit_records_are_emitted_on_success(): void
    {
        $job = $this->buildMockJob();

        ReportJobModel::shouldReceive('findOrFail')->andReturn($job);

        DB::shouldReceive('connection->transaction')->andReturnUsing(
            static fn (callable $cb) => $cb()
        );
        DB::shouldReceive('connection->table->where->where->count')->andReturn(0);
        DB::shouldReceive('connection->table->where->where->get')->andReturn(collect([]));

        $job->shouldReceive('transitionTo')->withAnyArgs()->andReturnNull();
        $job->shouldReceive('setAttribute')->withAnyArgs()->andReturnNull();
        $job->shouldReceive('save')->andReturnTrue();

        $this->audit->shouldReceive('record')
            ->withArgs(fn ($data) => str_contains($data->action, 'processing'))
            ->once();
        $this->audit->shouldReceive('record')
            ->withArgs(fn ($data) => str_contains($data->action, 'completed'))
            ->once();

        $this->outbox->shouldReceive('publish')->once();

        $handler = new GenerateWalletReportJob($job->id, 'corr-uuid-4');
        $handler->handle($this->audit, $this->outbox);
    }

    private function buildMockJob(): ReportJobModel&MockInterface
    {
        $job = Mockery::mock(ReportJobModel::class);
        $job->shouldReceive('getAttribute')->with('id')->andReturn('job-uuid-1');
        $job->shouldReceive('getAttribute')->with('report_type')->andReturn('wallet-report');
        $job->shouldReceive('getAttribute')->with('requested_by_user_id')->andReturn('user-uuid-1');
        $job->shouldReceive('getAttribute')->with('filters')->andReturn([
            'date_range_start' => '2026-01-01',
            'date_range_end'   => '2026-12-31',
        ]);
        $job->shouldReceive('getAttribute')->with('as_of_at')->andReturn(null);

        return $job;
    }
}
