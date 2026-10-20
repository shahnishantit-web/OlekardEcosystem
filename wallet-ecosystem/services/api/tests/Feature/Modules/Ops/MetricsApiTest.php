<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ops;

use App\Modules\Ops\Application\Services\MetricsEmitter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MetricsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_query_returns_data_points(): void
    {
        $this->actingAsAdmin(['ops.view_metrics']);
        $emitter = app(MetricsEmitter::class);
        $now     = Carbon::now();

        $emitter->emit('api', 'http.request_count', 5.0, 'count', $now);
        $emitter->emit('api', 'http.latency_ms',   42.0, 'ms',    $now);

        $response = $this->getJson('/api/v1/admin/ops/metrics?service_name=api&granularity=raw')
            ->assertOk();

        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
        $this->assertSame('http.request_count', $response->json('data.0.metric_name'));
    }

    public function test_returns_403_without_permission(): void
    {
        $this->actingAsAdmin([]);
        $this->getJson('/api/v1/admin/ops/metrics')->assertForbidden();
    }
}
