<?php

declare(strict_types=1);

namespace Tests\Performance\Modules\Identity;

use App\Modules\Identity\Domain\Services\PermissionResolverService;
use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityTestCase;

/**
 * T083 — SC-E04-010: hasPermission() P95 latency ≤ 10 ms with warm cache.
 *
 * SC-E04-010: "hasPermission() P95 latency ≤ 10 ms with warm Redis cache."
 *
 * Environment assumptions:
 *   - FakeRedisConnection (in-memory array) simulates the Redis cache-hit path
 *     without network round-trips. In production, real Redis on loopback adds
 *     < 1 ms, keeping P95 within the same order of magnitude.
 *   - PostgreSQL is running locally via the standard test configuration. A single
 *     indexed primary-key lookup (resolveActorType) is consistently < 5 ms local.
 *   - Measurements include the full hasPermission() call: cache read, permission
 *     in_array check, actor_type DB lookup, and metrics increment.
 *   - The threshold of 10 ms is asserted against the P95, not individual outliers.
 *     Cold JIT/OPcache warm-up is excluded via 5 pre-measurement warm-up calls.
 *
 * If this test fails in CI: verify PostgreSQL connectivity (SELECT 1 latency)
 * and confirm FakeRedisConnection is injected (IdentityTestCase setUp).
 */
final class HasPermissionPerformanceTest extends IdentityTestCase
{
    private string $actorId;
    private PermissionResolverService $resolver;

    private const WARMUP_CALLS   = 5;
    private const MEASURED_CALLS = 100;
    private const P95_THRESHOLD_MS = 10.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = $this->app->make(PermissionResolverService::class);

        $this->actorId = (string) \Ramsey\Uuid\Uuid::uuid7();
        $adminRole     = DB::connection('identity')->table('identity.roles')
            ->where('slug', 'admin')->first(['id']);

        DB::connection('identity')->table('identity.users')->insert([
            'id'              => $this->actorId,
            'email'           => 'perf-test@t.local',
            'mobile_number'   => '+1000008000',
            'status'          => 'active',
            'actor_type'      => 'ADMIN',
            'default_role_id' => $adminRole?->id,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    public function test_has_permission_p95_latency_is_within_spec_with_warm_cache(): void
    {
        // Warm the permission cache so subsequent calls hit FakeRedisConnection.
        $this->resolver->resolveAllForActor($this->actorId);

        // Warm-up: let PHP JIT and DB connection pool stabilise.
        for ($i = 0; $i < self::WARMUP_CALLS; $i++) {
            $this->resolver->hasPermission($this->actorId, 'admin.role.read');
        }

        // Measured iterations.
        $latenciesMs = [];
        for ($i = 0; $i < self::MEASURED_CALLS; $i++) {
            $start = hrtime(true);
            $this->resolver->hasPermission($this->actorId, 'admin.role.read');
            $end = hrtime(true);

            $latenciesMs[] = ($end - $start) / 1_000_000.0; // nanoseconds → milliseconds
        }

        sort($latenciesMs);

        // P95: the 95th-percentile value (95% of calls are at or below this latency).
        $p95Index = (int) ceil(0.95 * self::MEASURED_CALLS) - 1;
        $p95Ms    = $latenciesMs[$p95Index];

        $this->assertLessThanOrEqual(
            self::P95_THRESHOLD_MS,
            $p95Ms,
            sprintf(
                'SC-E04-010 FAILED: hasPermission() P95 = %.3f ms exceeds the %.1f ms threshold. '
                . 'Check PostgreSQL local latency and FakeRedisConnection injection. '
                . 'Min=%.3f ms, Median=%.3f ms, P95=%.3f ms, Max=%.3f ms.',
                $p95Ms,
                self::P95_THRESHOLD_MS,
                $latenciesMs[0],
                $latenciesMs[(int) ceil(0.50 * self::MEASURED_CALLS) - 1],
                $p95Ms,
                $latenciesMs[self::MEASURED_CALLS - 1],
            ),
        );
    }

    public function test_has_permission_false_path_p95_is_within_spec(): void
    {
        // Also verify the deny path (permission not granted) meets the threshold.
        // Deny path hits cache, performs in_array (false), resolves actor_type, increments deny metric.
        $this->resolver->resolveAllForActor($this->actorId);

        for ($i = 0; $i < self::WARMUP_CALLS; $i++) {
            $this->resolver->hasPermission($this->actorId, 'nonexistent.perm');
        }

        $latenciesMs = [];
        for ($i = 0; $i < self::MEASURED_CALLS; $i++) {
            $start = hrtime(true);
            $this->resolver->hasPermission($this->actorId, 'nonexistent.perm');
            $end = hrtime(true);

            $latenciesMs[] = ($end - $start) / 1_000_000.0;
        }

        sort($latenciesMs);
        $p95Index = (int) ceil(0.95 * self::MEASURED_CALLS) - 1;
        $p95Ms    = $latenciesMs[$p95Index];

        $this->assertLessThanOrEqual(
            self::P95_THRESHOLD_MS,
            $p95Ms,
            sprintf(
                'SC-E04-010 (deny path) FAILED: P95 = %.3f ms exceeds %.1f ms. '
                . 'Min=%.3f ms, P95=%.3f ms, Max=%.3f ms.',
                $p95Ms,
                self::P95_THRESHOLD_MS,
                $latenciesMs[0],
                $p95Ms,
                $latenciesMs[self::MEASURED_CALLS - 1],
            ),
        );
    }
}
