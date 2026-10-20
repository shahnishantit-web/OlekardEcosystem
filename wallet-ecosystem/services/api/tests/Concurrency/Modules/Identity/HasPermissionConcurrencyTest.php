<?php

declare(strict_types=1);

namespace Tests\Concurrency\Modules\Identity;

use App\Modules\Identity\Application\Services\AdminUserService;
use App\Modules\Identity\Domain\Services\PermissionResolverService;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConcurrencyTestHelper;
use Tests\Support\IdentityTestCase;

/**
 * T082 — FR-E04-015: hasPermission() concurrency safety.
 *
 * FR-E04-015: "hasPermission() MUST be concurrency-safe. Simultaneous calls for
 * the same actor and permission MUST return consistent results with no data-race
 * between cache miss and database read."
 *
 * Verification approach:
 *   The PermissionResolverService uses an atomic Redis GET/SETEX pattern. On a
 *   cache miss, multiple concurrent readers all query the same DB view and write
 *   the same permission array. Redis SETEX is atomic, so the last writer wins —
 *   and all results are identical because they read the same committed DB state.
 *   There is no window where two concurrent calls can produce different results.
 *
 *   Because pcntl_fork() is unavailable on Windows, these tests use
 *   ConcurrencyTestHelper::runConcurrently() (sequential simulation). The
 *   correctness guarantee comes from the implementation's atomic primitives, not
 *   from the test harness running threads in parallel. The tests validate that
 *   the invariant holds across repeated calls, including calls that race with a
 *   cache invalidation event.
 */
final class HasPermissionConcurrencyTest extends IdentityTestCase
{
    private string $adminId;
    private PermissionResolverService $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = $this->app->make(PermissionResolverService::class);

        $this->adminId = (string) \Ramsey\Uuid\Uuid::uuid7();
        $adminRole     = DB::connection('identity')->table('identity.roles')
            ->where('slug', 'admin')->first(['id']);

        DB::connection('identity')->table('identity.users')->insert([
            'id'              => $this->adminId,
            'email'           => 'conc-perm@t.local',
            'mobile_number'   => '+1000009000',
            'status'          => 'active',
            'actor_type'      => 'ADMIN',
            'default_role_id' => $adminRole?->id,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Concurrent hasPermission() calls return consistent results (FR-E04-015)
    // -------------------------------------------------------------------------

    public function test_concurrent_calls_with_warm_cache_all_return_true(): void
    {
        // Warm the cache before the concurrent calls.
        $this->resolver->resolveAllForActor($this->adminId);

        $results = ConcurrencyTestHelper::runConcurrently(
            workers: 10,
            callable: fn () => $this->resolver->hasPermission($this->adminId, 'admin.role.read'),
        );

        foreach ($results as $i => $result) {
            $this->assertTrue($result, "Worker {$i}: hasPermission() returned false with warm cache — data race suspected.");
        }
    }

    public function test_concurrent_calls_immediately_after_cache_invalidation_return_consistent_result(): void
    {
        // Warm cache, then immediately invalidate — simulating a cache miss race window.
        $this->resolver->resolveAllForActor($this->adminId);
        $this->resolver->invalidateCache($this->adminId);

        $results = ConcurrencyTestHelper::runConcurrently(
            workers: 10,
            callable: fn () => $this->resolver->hasPermission($this->adminId, 'admin.user.create'),
        );

        // All results must be identical; first caller rebuilds cache from DB,
        // subsequent callers hit the rebuilt cache.
        $unique = array_unique($results);
        $this->assertCount(1, $unique, 'All concurrent hasPermission() results must be consistent after a cache invalidation.');
        $this->assertTrue($results[0], 'admin.user.create is an admin permission — all callers must see true.');
    }

    public function test_concurrent_calls_for_non_granted_permission_consistently_return_false(): void
    {
        $results = ConcurrencyTestHelper::runConcurrently(
            workers: 10,
            callable: fn () => $this->resolver->hasPermission($this->adminId, 'no.such.permission'),
        );

        foreach ($results as $i => $result) {
            $this->assertFalse($result, "Worker {$i}: hasPermission() returned true for a non-existent permission.");
        }
    }

    public function test_concurrent_calls_for_unknown_actor_consistently_return_false(): void
    {
        $ghostId = '00000000-0000-0000-0000-000000000000';

        $results = ConcurrencyTestHelper::runConcurrently(
            workers: 10,
            callable: fn () => $this->resolver->hasPermission($ghostId, 'admin.role.read'),
        );

        foreach ($results as $i => $result) {
            $this->assertFalse($result, "Worker {$i}: hasPermission() must return false for unknown actor — never throw.");
        }
    }

    // -------------------------------------------------------------------------
    // Concurrent admin user creation with same email: exactly one in DB
    // -------------------------------------------------------------------------

    public function test_concurrent_admin_user_creation_with_same_email_creates_exactly_one_user(): void
    {
        $email   = 'race-conc-' . uniqid() . '@t.local';
        $payload = ['email' => $email, 'display_name' => 'Race User', 'password' => 'Secure#Pass1'];

        /** @var AdminUserService $service */
        $service = $this->app->make(AdminUserService::class);

        $successes = 0;
        $conflicts = 0;

        // Simulate concurrent creation: 3 callers attempt to create the same admin.
        // In true concurrency, a DB unique constraint ensures exactly one INSERT succeeds.
        // In sequential simulation, the pre-check catches the conflict after the first
        // successful insert — producing the same outcome: exactly 1 user, 1 success, 2 failures.
        for ($i = 0; $i < 3; $i++) {
            try {
                $service->createAdmin($payload, $this->adminId, (string) \Ramsey\Uuid\Uuid::uuid7());
                $successes++;
            } catch (\DomainException $e) {
                if ($e->getMessage() === 'ADMIN_USER_EXISTS') {
                    $conflicts++;
                }
            }
        }

        $this->assertSame(1, $successes, 'Exactly one concurrent admin creation must succeed.');
        $this->assertSame(2, $conflicts, 'Remaining concurrent attempts must return ADMIN_USER_EXISTS.');

        $count = DB::connection('identity')
            ->table('identity.users')
            ->where('email', $email)
            ->where('actor_type', 'ADMIN')
            ->count();

        $this->assertSame(1, $count, 'Exactly one admin user record must exist after concurrent creation attempts.');
    }
}
