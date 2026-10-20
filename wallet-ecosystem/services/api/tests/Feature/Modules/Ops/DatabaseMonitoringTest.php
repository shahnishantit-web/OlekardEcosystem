<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ops;

use App\Modules\Ops\Application\Services\DatabaseMonitoringService;
use App\Modules\Ops\Domain\Contracts\MetricRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DatabaseMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_collect_writes_at_least_three_metric_names(): void
    {
        $service = app(DatabaseMonitoringService::class);
        $service->collect();

        /** @var MetricRepositoryInterface $repo */
        $repo    = app(MetricRepositoryInterface::class);
        $metrics = $repo->query('database', null, 'raw', Carbon::now()->subMinute(), Carbon::now()->addMinute());

        $names = $metrics->pluck('metric_name')->unique()->values()->toArray();
        $this->assertContains('db.active_connections', $names);
        $this->assertContains('db.storage_bytes',      $names);
        $this->assertContains('db.slow_query_count',   $names);
    }

    public function test_db_metrics_queryable_via_admin_api(): void
    {
        $this->actingAsAdmin(['ops.view_metrics']);
        app(DatabaseMonitoringService::class)->collect();

        $this->getJson('/api/v1/admin/ops/metrics?service_name=database&granularity=raw')
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }
}
