<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ops;

use App\Modules\Ops\Domain\Enums\BackupJobStatus;
use App\Modules\Ops\Domain\Enums\BackupType;
use App\Modules\Ops\Domain\Enums\LogSeverity;
use App\Modules\Ops\Domain\Enums\ProviderStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * @group docker
 *
 * E30 DevOps & Operations — Quickstart SC-01 through SC-10.
 * Validates acceptance scenarios from specs/030-devops-operations/quickstart.md.
 */
final class DevOpsQuickstartTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['platform', 'identity'];

    // ── SC-01: Health probe (US-OPS-003) ──────────────────────────────────────

    /** SC-01: GET /v1/ops/health returns 200 with overall_status=healthy and component breakdown. */
    public function test_sc01_health_probe_returns_healthy_status(): void
    {
        $response = $this->getJson('/v1/ops/health');

        $response->assertStatus(200);
        $response->assertJsonPath('data.overall_status', 'healthy');
        $response->assertJsonStructure(['data' => ['overall_status', 'components', 'checked_at']]);

        $names = array_column($response->json('data.components'), 'name');
        $this->assertContains('database', $names, 'database component must be present');
        $this->assertContains('cache',    $names, 'cache component must be present');
        $this->assertContains('queue',    $names, 'queue component must be present');
    }

    // ── SC-02: Readiness probe (US-OPS-004) ───────────────────────────────────

    /** SC-02: GET /v1/ops/health/ready returns 200 when all services are up. */
    public function test_sc02_readiness_probe_returns_200(): void
    {
        $this->getJson('/v1/ops/health/ready')->assertStatus(200);
    }

    // ── SC-03: Liveness probe (US-OPS-005) ────────────────────────────────────

    /** SC-03: GET /v1/ops/health/live returns 200 with status=live (no external dependency). */
    public function test_sc03_liveness_probe_returns_live_status(): void
    {
        $response = $this->getJson('/v1/ops/health/live');

        $response->assertStatus(200);
        $this->assertSame('live', $response->json('data.status'), 'Liveness status must be "live"');
    }

    // ── SC-04: Log search by correlation ID (US-OPS-001) ─────────────────────

    /** SC-04: Log entries seeded with a correlation ID are returned by the search endpoint. */
    public function test_sc04_log_search_by_correlation_id_returns_entries(): void
    {
        $correlationId = (string) Uuid::uuid7();

        DB::connection('platform')->table('ops.log_entries')->insert([
            'id'             => (string) Uuid::uuid7(),
            'service_name'   => 'api',
            'correlation_id' => $correlationId,
            'severity'       => LogSeverity::Info->value,
            'message'        => 'Quickstart test log entry',
            'context'        => json_encode(['request_id' => 'qs-001']),
            'created_at'     => now()->toIso8601String(),
        ]);

        $this->actingAsAdminWithPermission('ops.view_logs');

        $response = $this->getJson("/v1/admin/ops/logs?correlation_id={$correlationId}");

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'), 'At least one log entry must be returned');
        $this->assertSame(
            $correlationId,
            $response->json('data.0.correlation_id'),
            'Returned entry must match the seeded correlation ID'
        );
    }

    // ── SC-05: Metrics query (US-OPS-002) ─────────────────────────────────────

    /** SC-05: GET /v1/admin/ops/metrics returns at least one data point when a metric is seeded. */
    public function test_sc05_metrics_query_returns_data_points(): void
    {
        DB::connection('platform')->table('ops.service_metrics')->insert([
            'id'           => (string) Uuid::uuid7(),
            'service_name' => 'api',
            'metric_name'  => 'http.request_count',
            'value'        => 42.0,
            'unit'         => 'count',
            'granularity'  => 'raw',
            'recorded_at'  => now()->toIso8601String(),
            'created_at'   => now()->toIso8601String(),
        ]);

        $this->actingAsAdminWithPermission('ops.view_metrics');

        $response = $this->getJson('/v1/admin/ops/metrics?service_name=api&granularity=raw');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data, 'At least one metric data point must be returned');

        $this->assertArrayHasKey('metric_name', $data[0]);
        $this->assertArrayHasKey('value',       $data[0]);
        $this->assertArrayHasKey('unit',        $data[0]);
    }

    // ── SC-06: Provider status list (US-OPS-008) ──────────────────────────────

    /** SC-06: GET /v1/admin/ops/providers returns a list of provider status entries. */
    public function test_sc06_provider_status_list_returns_entries(): void
    {
        DB::connection('platform')->table('ops.provider_statuses')->insert([
            'id'                  => (string) Uuid::uuid7(),
            'provider_type'       => 'payment_gateway',
            'status'              => ProviderStatus::Up->value,
            'consecutive_failures'=> 0,
            'last_checked_at'     => now()->toIso8601String(),
            'created_at'          => now()->toIso8601String(),
            'updated_at'          => now()->toIso8601String(),
        ]);

        $this->actingAsAdminWithPermission('ops.view_health');

        $response = $this->getJson('/v1/admin/ops/providers');

        $response->assertStatus(200);
        $this->assertIsArray($response->json('data'), 'Response data must be an array');
        $this->assertNotEmpty($response->json('data'), 'At least one provider status must be returned');

        $types = array_column($response->json('data'), 'provider_type');
        $this->assertContains('payment_gateway', $types, 'Seeded provider must appear in the list');
    }

    // ── SC-07: Alert rule lifecycle (US-OPS-009) ──────────────────────────────

    /** SC-07: Alert rule can be created, disabled, and deleted via the API. */
    public function test_sc07_alert_rule_create_disable_and_delete_lifecycle(): void
    {
        $this->actingAsAdminWithPermission('ops.manage_alerts');

        // Create
        $createResponse = $this->postJson('/v1/admin/ops/alert-rules', [
            'name'                  => 'QS High Error Rate',
            'metric_condition'      => [
                'service_name'   => 'api',
                'metric_name'    => 'http.error_rate',
                'operator'       => 'gt',
                'threshold'      => 0.05,
                'window_minutes' => 5,
            ],
            'severity'              => 'warning',
            'notification_channels' => [['type' => 'email', 'config' => ['address' => 'ops@olekard.local']]],
        ], ['Idempotency-Key' => (string) Uuid::uuid7()]);

        $createResponse->assertStatus(201);
        $ruleId = $createResponse->json('data.id');
        $this->assertNotEmpty($ruleId, 'Created rule must have an ID');
        $this->assertTrue($createResponse->json('data.is_enabled'), 'New rule must be enabled by default');

        // Disable
        $disableResponse = $this->putJson("/v1/admin/ops/alert-rules/{$ruleId}", [
            'is_enabled' => false,
        ], ['Idempotency-Key' => (string) Uuid::uuid7()]);

        $disableResponse->assertStatus(200);
        $this->assertFalse($disableResponse->json('data.is_enabled'), 'Rule must be disabled after update');

        // Delete
        $deleteResponse = $this->deleteJson("/v1/admin/ops/alert-rules/{$ruleId}", [], [
            'Idempotency-Key' => (string) Uuid::uuid7(),
        ]);

        $deleteResponse->assertStatus(200);
        $this->assertTrue($deleteResponse->json('data.deleted'), 'Delete must return deleted=true');
    }

    // ── SC-08: Manual backup with idempotency (US-OPS-010) ───────────────────

    /** SC-08: POST backup creates a job; same idempotency key returns the same job ID. */
    public function test_sc08_manual_backup_and_idempotency_replay(): void
    {
        $this->actingAsAdminWithPermission('ops.manage_backup');

        $idempotencyKey = (string) Uuid::uuid7();

        // First call — create backup
        $first = $this->postJson('/v1/admin/ops/backup-jobs', [
            'type' => BackupType::Full->value,
        ], ['Idempotency-Key' => $idempotencyKey]);

        $first->assertStatus(202);
        $jobId = $first->json('data.id');
        $this->assertNotEmpty($jobId, 'Backup job must have an ID');
        $this->assertNotEmpty($first->json('data.status'), 'Backup job must have a status');

        // Idempotency replay — same key, same job
        $replay = $this->postJson('/v1/admin/ops/backup-jobs', [
            'type' => BackupType::Full->value,
        ], ['Idempotency-Key' => $idempotencyKey]);

        $replay->assertStatus(202);
        $this->assertSame($jobId, $replay->json('data.id'), 'Idempotency replay must return the original job');
    }

    // ── SC-09: Deployment version tracking and rollback (US-OPS-012) ─────────

    /** SC-09: Version list returns seeded versions; rollback initiation returns 202 with rollback record. */
    public function test_sc09_deployment_version_list_and_rollback_initiation(): void
    {
        DB::connection('platform')->table('ops.deployment_versions')->insert([
            [
                'id'          => (string) Uuid::uuid7(),
                'version'     => 'v1.1.0',
                'deployed_at' => now()->subDay()->toIso8601String(),
                'is_current'  => false,
                'created_at'  => now()->subDay()->toIso8601String(),
                'updated_at'  => now()->subDay()->toIso8601String(),
            ],
            [
                'id'          => (string) Uuid::uuid7(),
                'version'     => 'v1.2.0',
                'deployed_at' => now()->toIso8601String(),
                'is_current'  => true,
                'created_at'  => now()->toIso8601String(),
                'updated_at'  => now()->toIso8601String(),
            ],
        ]);

        $this->actingAsAdminWithPermission('ops.manage_deployments');

        // List versions
        $listResponse = $this->getJson('/v1/admin/ops/deployments/versions');

        $listResponse->assertStatus(200);
        $this->assertNotEmpty($listResponse->json('data'), 'Version list must contain the seeded versions');
        $versions = array_column($listResponse->json('data'), 'version');
        $this->assertContains('v1.2.0', $versions, 'Current version must appear in the list');

        // Initiate rollback to previous version
        $rollbackResponse = $this->postJson('/v1/admin/ops/deployments/rollback', [
            'to_version' => 'v1.1.0',
            'reason'     => 'Quickstart validation rollback',
        ], ['Idempotency-Key' => (string) Uuid::uuid7()]);

        $rollbackResponse->assertStatus(202);
        $this->assertNotEmpty($rollbackResponse->json('data'), 'Rollback response must contain a data object');
    }

    // ── SC-10: Authorization enforcement ─────────────────────────────────────

    /** SC-10a: Request without auth header returns 401 UNAUTHORIZED. */
    public function test_sc10_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/v1/admin/ops/logs')->assertStatus(401);
    }

    /** SC-10b: Actor with an unrelated permission on an ops endpoint returns 403 FORBIDDEN. */
    public function test_sc10_actor_without_ops_permission_returns_403(): void
    {
        $this->actingAsAdminWithPermission('multi_currency.view_balances');

        $this->getJson('/v1/admin/ops/logs')->assertStatus(403);
    }
}
