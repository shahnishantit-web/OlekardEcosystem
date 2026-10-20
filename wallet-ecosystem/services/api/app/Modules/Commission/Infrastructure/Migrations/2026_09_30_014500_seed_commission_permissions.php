<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    protected $connection = 'identity';

    private function now(): string
    {
        return now()->toDateTimeString();
    }

    public function up(): void
    {
        $permissions = [
            ['slug' => 'commission.rules.create',  'display_name' => 'Create Commission Rules (Admin)'],
            ['slug' => 'commission.rules.view',    'display_name' => 'View Commission Rules'],
            ['slug' => 'commission.rules.update',  'display_name' => 'Update/Supersede Commission Rules (Admin)'],
            ['slug' => 'commission.rules.delete',  'display_name' => 'Deactivate Commission Rules (Admin)'],
            ['slug' => 'commission.view_own',      'display_name' => 'View Own Commission History (Agent)'],
            ['slug' => 'commission.view_any',      'display_name' => 'View Any Agent Commission History (Admin)'],
            ['slug' => 'commission.payout.approve','display_name' => 'Approve and Execute Commission Payouts (Admin)'],
            ['slug' => 'commission.payout.cancel', 'display_name' => 'Cancel Deferred Commission Payouts (Admin)'],
            ['slug' => 'commission.reverse',       'display_name' => 'Reverse Commission (Admin)'],
            ['slug' => 'commission.tier.manage',   'display_name' => 'Set Agent Commission Tier (Admin)'],
            ['slug' => 'commission.report.view',   'display_name' => 'View Commission Report (Admin)'],
        ];

        $now = $this->now();

        foreach ($permissions as $perm) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id'                   => (string) Str::orderedUuid(),
                'slug'                 => $perm['slug'],
                'display_name'         => $perm['display_name'],
                'description'          => null,
                'module'               => 'commission',
                'is_system_permission' => true,
                'deleted_at'           => null,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
        }

        $allPermIds = DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', array_column($permissions, 'slug'))
            ->pluck('id', 'slug');

        $adminRoleId = DB::connection('identity')->table('identity.roles')
            ->where('slug', 'admin')
            ->value('id');

        if ($adminRoleId !== null) {
            foreach ($allPermIds as $permId) {
                DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                    'id'                  => (string) Str::orderedUuid(),
                    'role_id'             => $adminRoleId,
                    'permission_id'       => $permId,
                    'created_by_actor_id' => null,
                    'created_at'          => $now,
                ]);
            }
        }

        $agentRoleId = DB::connection('identity')->table('identity.roles')
            ->where('slug', 'agent')
            ->value('id');

        if ($agentRoleId !== null) {
            $agentSlugs = ['commission.view_own'];

            foreach ($agentSlugs as $slug) {
                if (isset($allPermIds[$slug])) {
                    DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                        'id'                  => (string) Str::orderedUuid(),
                        'role_id'             => $agentRoleId,
                        'permission_id'       => $allPermIds[$slug],
                        'created_by_actor_id' => null,
                        'created_at'          => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $slugs = [
            'commission.rules.create', 'commission.rules.view', 'commission.rules.update',
            'commission.rules.delete', 'commission.view_own', 'commission.view_any',
            'commission.payout.approve', 'commission.payout.cancel', 'commission.reverse',
            'commission.tier.manage', 'commission.report.view',
        ];

        $permissionIds = DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', $slugs)
            ->pluck('id');

        DB::connection('identity')->table('identity.role_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->delete();

        DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', $slugs)
            ->where('is_system_permission', true)
            ->delete();
    }
};
