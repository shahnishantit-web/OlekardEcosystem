<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Domain\Services\PermissionResolverService;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Provision the local demo administrator from the application's route policy. */
final class DevAdminFullPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('DevAdminFullPermissionsSeeder only runs in local/testing.');
            return;
        }
        $db = DB::connection('identity');
        $user = $db->table('identity.users')->where('email', 'admin@olekard.local')->first();
        if ($user === null || $user->actor_type !== 'ADMIN') {
            throw new \RuntimeException('The local ADMIN account admin@olekard.local must exist first.');
        }
        // Register explicit route policies missing from older local snapshots.
        // Never invent a wildcard or bypass authorization.
        $slugs = [];
        foreach (app('router')->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (str_starts_with($middleware, 'identity.require-permission:')) {
                    $slugs[] = substr($middleware, strlen('identity.require-permission:'));
                }
            }
        }
        $db->transaction(function () use ($db, $user, $slugs): void {
            foreach (array_unique($slugs) as $slug) {
                // Do not revive soft-deleted definitions.
                if ($db->table('identity.permissions')->where('slug', $slug)->exists()) continue;
                $db->table('identity.permissions')->insert([
                    'id' => (string) Str::uuid7(), 'slug' => $slug,
                    'display_name' => Str::headline(str_replace('.', ' ', $slug)),
                    'description' => 'Declared API permission: '.$slug,
                    'module' => Str::before($slug, '.'), 'is_system_permission' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $roleId = $db->table('identity.roles')->where('slug', 'admin')->whereNull('deleted_at')->value('id');
            if ($roleId === null) {
                $roleId = (string) Str::uuid7();
                $db->table('identity.roles')->insert([
                    'id' => $roleId, 'slug' => 'admin', 'display_name' => 'Administrator',
                    'description' => 'Local full-access administrator', 'is_system_role' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            if (! $db->table('identity.user_roles')->where('user_id', $user->id)->where('role_id', $roleId)->exists()) {
                $db->table('identity.user_roles')->insert([
                    'id' => (string) Str::uuid7(), 'user_id' => $user->id, 'role_id' => $roleId,
                    'created_by_actor_id' => $user->id, 'created_at' => now(),
                ]);
            }
            foreach ($db->table('identity.permissions')->whereNull('deleted_at')->pluck('id') as $permissionId) {
                if ($db->table('identity.role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) continue;
                $db->table('identity.role_permissions')->insert([
                    'id' => (string) Str::uuid7(), 'role_id' => $roleId, 'permission_id' => $permissionId,
                    'created_by_actor_id' => $user->id, 'created_at' => now(),
                ]);
            }
        });
        app(PermissionResolverService::class)->invalidateCache($user->id);
        app(AuditServiceInterface::class)->record(new AuditEventData(
            action: 'identity.dev_admin.permissions_seeded', actorType: 'admin',
            resourceType: 'User', correlationId: (string) Str::uuid7(), outcome: 'success',
            actorId: $user->id, resourceId: $user->id,
            metadata: ['source' => self::class, 'declared_route_permissions' => count(array_unique($slugs))],
        ));
        $this->command?->info('Local admin permissions synchronized; sign in again to refresh UI permission claims.');
    }
}