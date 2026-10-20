<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ops;

use App\Modules\Ops\Application\Services\QueueMonitoringService;
use App\Modules\Ops\Domain\Contracts\MetricRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class QueueMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_collect_writes_metrics_for_at_least_one_queue(): void
    {
        app(QueueMonitoringService::class)->collect();

        /** @var MetricRepositoryInterface $repo */
        $repo    = app(MetricRepositoryInterface::class);
        $metrics = $repo->query('queue.default', null, 'raw', Carbon::now()->subMinute(), Carbon::now()->addMinute());

        $this->assertGreaterThanOrEqual(1, $metrics->count());
    }

    public function test_queue_metrics_queryable_via_admin_api(): void
    {
        $this->actingAsAdmin(['ops.view_metrics']);
        app(QueueMonitoringService::class)->collect();

        $this->getJson('/api/v1/admin/ops/metrics?service_name=queue.default&granularity=raw')
            ->assertOk();
    }
}
