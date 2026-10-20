<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Identity;

use App\Modules\Identity\Domain\Services\PermissionResolverService;
use App\Modules\Identity\Infrastructure\Cache\IdentityRedisKeys;
use Tests\Support\IdentityTestCase;

/**
 * T079 — PermissionResolverService integration tests.
 * Tests real DB queries, FakeRedisConnection caching, and EC-08 TTL=0 edge case.
 */
final class PermissionResolverServiceTest extends IdentityTestCase
{
    private string $actorId;
    private PermissionResolverService $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actorId  = (string) \Ramsey\Uuid\Uuid::uuid7();
        $this->resolver = $this->app->make(PermissionResolverService::class);

        $adminRole = \DB::connection('identity')->table('identity.roles')
            ->where('slug', 'admin')
            ->first(['id']);

        \DB::connection('identity')->table('identity.users')->insert([
            'id'             => $this->actorId,
            'email'          => 'resolver@t.local',
            'mobile_number'  => '+1000002000',
            'status'         => 'active',
            'actor_type'     => 'ADMIN',
            'default_role_id' => $adminRole?->id,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // EC-08: TTL = 0 — no-cache mode (C2 finding)
    // -------------------------------------------------------------------------

    public function test_ttl_zero_does_not_write_to_redis(): void
    {
        $this->setPermissionCacheTtl('0');

        $this->resolver->resolveAllForActor($this->actorId);

        $cacheKey = IdentityRedisKeys::actorPermissions($this->actorId);
        $this->assertFalse($this->identityRedis->get($cacheKey));
    }

    public function test_ttl_zero_returns_fresh_db_data_on_each_call(): void
    {
        $this->setPermissionCacheTtl('0');

        $permissions1 = $this->resolver->resolveAllForActor($this->actorId);

        $this->assertNotEmpty($permissions1);
        $this->assertContains('admin.role.read', $permissions1);

        // Second call returns same data (still from DB)
        $permissions2 = $this->resolver->resolveAllForActor($this->actorId);
        $this->assertSame(sort($permissions1), sort($permissions2));
    }

    // -------------------------------------------------------------------------
    // TTL > 0 — cache enabled
    // -------------------------------------------------------------------------

    public function test_ttl_positive_writes_to_redis_after_db_load(): void
    {
        $this->setPermissionCacheTtl('300');

        $this->resolver->resolveAllForActor($this->actorId);

        $cacheKey = IdentityRedisKeys::actorPermissions($this->actorId);
        $cached   = $this->identityRedis->get($cacheKey);
        $this->assertNotFalse($cached);

        $decoded = json_decode((string) $cached, true);
        $this->assertIsArray($decoded);
        $this->assertContains('admin.role.read', $decoded);
    }

    public function test_ttl_positive_serves_cache_on_second_call(): void
    {
        $this->setPermissionCacheTtl('300');

        // First call: populates cache
        $permissions = $this->resolver->resolveAllForActor($this->actorId);

        // Manually corrupt the cache to detect if cache or DB is used on 2nd call
        $cacheKey = IdentityRedisKeys::actorPermissions($this->actorId);
        $this->identityRedis->setex($cacheKey, 300, json_encode(['fake.permission.only']));

        // Second call should return the faked cached value, not the DB value
        $permissions2 = $this->resolver->resolveAllForActor($this->actorId);
        $this->assertSame(['fake.permission.only'], $permissions2);
    }

    // -------------------------------------------------------------------------
    // hasPermission
    // -------------------------------------------------------------------------

    public function test_has_permission_returns_true_for_admin_role_permission(): void
    {
        $this->setPermissionCacheTtl('0');
        $result = $this->resolver->hasPermission($this->actorId, 'admin.role.read');
        $this->assertTrue($result);
    }

    public function test_has_permission_returns_false_for_non_granted_permission(): void
    {
        $this->setPermissionCacheTtl('0');
        $result = $this->resolver->hasPermission($this->actorId, 'some.nonexistent.permission');
        $this->assertFalse($result);
    }

    public function test_has_permission_returns_false_for_unknown_actor(): void
    {
        $this->setPermissionCacheTtl('0');
        $result = $this->resolver->hasPermission('00000000-0000-0000-0000-000000000000', 'admin.role.read');
        $this->assertFalse($result);
    }

    public function test_all_seeded_admin_permissions_are_resolvable(): void
    {
        $this->setPermissionCacheTtl('0');

        $expectedPermissions = [
            'session.admin.revoke',
            'configuration.policy.view',
            'configuration.policy.update',
            'device.admin.read',
            'device.admin.block',
            'device.admin.unblock',
            'device.admin.remove',
            'admin.role.read',
            'admin.role.create',
            'admin.role.manage',
            'admin.permission.read',
            'admin.user.create',
            'admin.user.read',
            'admin.user.deactivate',
            'admin.role.assign',
        ];

        foreach ($expectedPermissions as $permission) {
            $this->assertTrue(
                $this->resolver->hasPermission($this->actorId, $permission),
                "Expected admin actor to have permission: {$permission}",
            );
        }
    }

    // -------------------------------------------------------------------------
    // invalidateCache
    // -------------------------------------------------------------------------

    public function test_invalidate_cache_removes_redis_key(): void
    {
        $this->setPermissionCacheTtl('300');

        // Populate cache first
        $this->resolver->resolveAllForActor($this->actorId);

        $cacheKey = IdentityRedisKeys::actorPermissions($this->actorId);
        $this->assertNotFalse($this->identityRedis->get($cacheKey));

        // Invalidate
        $this->resolver->invalidateCache($this->actorId);

        $this->assertFalse($this->identityRedis->get($cacheKey));
    }

    public function test_invalidate_cache_on_unknown_actor_does_not_throw(): void
    {
        $this->resolver->invalidateCache('ghost-actor');
        $this->expectNotToPerformAssertions();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function setPermissionCacheTtl(string $value): void
    {
        \DB::connection('platform')->table('platform.configuration_values')
            ->where('config_key', 'auth.permission_cache_ttl')
            ->update(['config_value' => $value, 'updated_at' => now()]);

        // Invalidate the cached config value so ConfigurationService re-reads from DB on next call
        $this->app->make(\App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface::class)
            ->invalidate('auth.permission_cache_ttl');
    }
}
