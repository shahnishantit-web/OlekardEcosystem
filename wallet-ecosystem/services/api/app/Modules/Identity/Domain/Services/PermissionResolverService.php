<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Domain\Repositories\RolePermissionRepositoryInterface;
use App\Modules\Identity\Domain\Repositories\RoleRepositoryInterface;
use App\Modules\Identity\Domain\Repositories\UserRoleRepositoryInterface;
use App\Modules\Identity\Infrastructure\Cache\IdentityRedisKeys;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Resolves the full permission set for an actor, with Redis-backed caching.
 *
 * EC-08: When auth.permission_cache_ttl = 0, caching is disabled and the DB is
 * queried on every call. No key is written to Redis in the TTL=0 case.
 *
 * Cache invalidation is synchronous (SEC-E04-007): callers must invoke
 * invalidateCache() immediately after any role/permission change.
 */
final class PermissionResolverService
{
    public function __construct(
        private readonly RoleRepositoryInterface $roles,
        private readonly UserRoleRepositoryInterface $userRoles,
        private readonly RolePermissionRepositoryInterface $rolePermissions,
        private readonly ConfigurationServiceInterface $config,
        private readonly MetricsCollectorInterface $metrics,
    ) {}

    /**
     * Resolve all permission slugs for an actor.
     * Writes to Redis cache unless TTL=0 (no-cache mode).
     * Returns empty array for unknown actor without throwing.
     */
    public function resolveAllForActor(string $actorId): array
    {
        $ttl = $this->getCacheTtl();

        if ($ttl > 0) {
            $cached = $this->readFromCache($actorId);
            if ($cached !== null) {
                return $cached;
            }
        }

        $slugs = $this->loadFromDatabase($actorId);

        if ($ttl > 0) {
            $this->writeToCache($actorId, $slugs, $ttl);
        }

        return $slugs;
    }

    /**
     * Check if an actor has a specific permission.
     * Uses the cache if TTL > 0, otherwise queries the DB.
     * Returns false (never throws) for unknown actors or missing permissions.
     */
    public function hasPermission(string $actorId, string $permission): bool
    {
        $permissions = $this->resolveAllForActor($actorId);
        $granted     = in_array($permission, $permissions, true);

        $actorType = $this->resolveActorType($actorId);

        if ($granted) {
            $this->metrics->increment('identity.authorization.permission_granted', [
                'permission' => $permission,
                'actor_type' => $actorType,
            ]);
        } else {
            $this->metrics->increment('identity.authorization.permission_denied', [
                'permission' => $permission,
                'actor_type' => $actorType,
            ]);
        }

        return $granted;
    }

    /**
     * Delete the actor's permission cache key synchronously.
     * Must be called after any role or permission assignment change (SEC-E04-007).
     */
    public function invalidateCache(string $actorId): void
    {
        Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)
            ->del(IdentityRedisKeys::actorPermissions($actorId));
    }

    private function loadFromDatabase(string $actorId): array
    {
        $user = DB::connection('identity')
            ->table('identity.users')
            ->where('id', $actorId)
            ->first(['default_role_id']);

        if ($user === null) {
            return [];
        }

        $roleIds = array_filter([$user->default_role_id ?? null]);

        $additionalRoleIds = $this->userRoles->getActorRoleIds($actorId);

        $allRoleIds = array_unique(array_merge($roleIds, $additionalRoleIds));

        if (empty($allRoleIds)) {
            return [];
        }

        return $this->rolePermissions->getPermissionSlugsForRoles($allRoleIds);
    }

    private function readFromCache(string $actorId): ?array
    {
        $raw = Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)
            ->get(IdentityRedisKeys::actorPermissions($actorId));

        if ($raw === null || $raw === false) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function writeToCache(string $actorId, array $slugs, int $ttl): void
    {
        Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)->setex(
            IdentityRedisKeys::actorPermissions($actorId),
            $ttl,
            json_encode($slugs),
        );
    }

    private function getCacheTtl(): int
    {
        return (int) $this->config->getOrDefault('auth.permission_cache_ttl', 300);
    }

    private function resolveActorType(string $actorId): string
    {
        $user = DB::connection('identity')
            ->table('identity.users')
            ->where('id', $actorId)
            ->first(['actor_type']);

        return $user !== null ? (string) $user->actor_type : 'UNKNOWN';
    }
}
