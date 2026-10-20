<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HealthCheckApiTest extends TestCase
{
    use RefreshDatabase;

    // ── Health (US-OPS-003) ────────────────────────────────────────────────

    public function test_health_returns_200_with_healthy_status(): void
    {
        $this->getJson('/api/v1/ops/health')
            ->assertOk()
            ->assertJsonPath('data.overall_status', 'healthy')
            ->assertJsonStructure(['data' => ['overall_status', 'components', 'checked_at']]);
    }

    public function test_health_returns_without_authentication(): void
    {
        // No actingAs — proves no auth middleware is applied.
        $this->getJson('/api/v1/ops/health')->assertOk();
    }

    public function test_health_response_includes_component_breakdown(): void
    {
        $response = $this->getJson('/api/v1/ops/health')->assertOk();
        $names    = array_column($response->json('data.components'), 'name');
        $this->assertContains('database', $names);
        $this->assertContains('cache',    $names);
        $this->assertContains('queue',    $names);
    }

    // ── Readiness (US-OPS-004) ─────────────────────────────────────────────

    public function test_readiness_returns_200_when_db_is_available(): void
    {
        $this->getJson('/api/v1/ops/health/ready')
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');
    }

    public function test_readiness_returns_without_authentication(): void
    {
        $this->getJson('/api/v1/ops/health/ready')->assertOk();
    }

    // ── Liveness (US-OPS-005) ──────────────────────────────────────────────

    public function test_liveness_returns_200_live(): void
    {
        $this->getJson('/api/v1/ops/health/live')
            ->assertOk()
            ->assertJsonPath('data.status', 'live');
    }

    public function test_liveness_returns_without_authentication(): void
    {
        $this->getJson('/api/v1/ops/health/live')->assertOk();
    }

    public function test_liveness_does_not_fail_when_db_disconnected(): void
    {
        DB::disconnect('platform');
        $this->getJson('/api/v1/ops/health/live')->assertOk()
            ->assertJsonPath('data.status', 'live');
    }
}
