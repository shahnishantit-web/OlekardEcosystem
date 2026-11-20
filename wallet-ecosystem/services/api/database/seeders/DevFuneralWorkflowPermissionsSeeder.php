<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Domain\Services\PermissionResolverService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Local-only role grants for the Funeral Assistance maker/checker workflow. */
final class DevFuneralWorkflowPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('DevFuneralWorkflowPermissionsSeeder only runs in local/testing.');
        }

        $identity = DB::connection('identity');
        $assignments = [
            'local-event-creator' => [
                'community.funeral.manage',
                'community.funeral.payout.request',
                'configuration.policy.view',
                'configuration.policy.update',
            ],
            'local-event-reviewer' => [
                'community.funeral.review',
                'community.funeral.report',
                'community.funeral.payout.approve',
            ],
            'local-event-approver' => [
                'configuration.policy.view',
                'configuration.approve',
            ],
        ];

        foreach ($assignments as $roleSlug => $slugs) {
            $roleId = $identity->table('identity.roles')
                ->where('slug', $roleSlug)->whereNull('deleted_at')->value('id');
            if ($roleId === null) {
                throw new \RuntimeException("Missing local workflow role '{$roleSlug}'. Run DevEventWorkflowUsersSeeder first.");
            }

            foreach ($slugs as $slug) {
                $identity->table('identity.permissions')->insertOrIgnore([
                    'id' => (string) Str::uuid7(),
                    'slug' => $slug,
                    'display_name' => Str::headline(str_replace('.', ' ', $slug)),
                    'module' => str_starts_with($slug, 'community.funeral.') ? 'community' : 'platform',
                    'is_system_permission' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $permissionId = $identity->table('identity.permissions')->where('slug', $slug)->value('id');
                $identity->table('identity.role_permissions')->insertOrIgnore([
                    'id' => (string) Str::uuid7(),
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                ]);
            }

            $userIds = $identity->table('identity.users')->where('default_role_id', $roleId)->pluck('id')
                ->merge($identity->table('identity.user_roles')->where('role_id', $roleId)->pluck('user_id'))
                ->unique();
            foreach ($userIds as $userId) {
                app(PermissionResolverService::class)->invalidateCache((string) $userId);
            }
        }

        $this->command?->info('Local Funeral Assistance creator, reviewer, and approver permissions provisioned.');
    }
}
