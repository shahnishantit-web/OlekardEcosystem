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
            ['slug' => 'support.create',      'display_name' => 'Create Support Ticket'],
            ['slug' => 'support.assign',       'display_name' => 'Assign Support Ticket'],
            ['slug' => 'support.resolve',      'display_name' => 'Resolve Support Ticket'],
            ['slug' => 'support.close',        'display_name' => 'Close Support Ticket'],
            ['slug' => 'support.investigate',  'display_name' => 'Investigate Dispute'],
            ['slug' => 'dispute.raise',        'display_name' => 'Raise Transaction Dispute'],
            ['slug' => 'dispute.approve',      'display_name' => 'Approve Dispute'],
            ['slug' => 'dispute.reject',       'display_name' => 'Reject Dispute'],
            ['slug' => 'dispute.refund',       'display_name' => 'Execute Dispute Refund'],
            ['slug' => 'dispute.escalate',     'display_name' => 'Escalate Dispute'],
        ];

        $now = $this->now();

        foreach ($permissions as $perm) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id'                   => (string) Str::orderedUuid(),
                'slug'                 => $perm['slug'],
                'display_name'         => $perm['display_name'],
                'description'          => null,
                'module'               => 'support',
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

        $customerRoleId = DB::connection('identity')->table('identity.roles')
            ->where('slug', 'customer')
            ->value('id');

        $agentRoleId = DB::connection('identity')->table('identity.roles')
            ->where('slug', 'agent')
            ->value('id');

        $rolePermissions = [
            // customer: create ticket, raise dispute, escalate dispute
            'customer' => ['support.create', 'dispute.raise', 'dispute.escalate'],
            // agent: create ticket, raise dispute, escalate dispute
            'agent'    => ['support.create', 'dispute.raise', 'dispute.escalate'],
            // admin: all permissions
            'admin'    => [
                'support.create', 'support.assign', 'support.resolve', 'support.close',
                'support.investigate', 'dispute.raise', 'dispute.approve', 'dispute.reject',
                'dispute.refund', 'dispute.escalate',
            ],
        ];

        $roleIds = [
            'customer' => $customerRoleId,
            'agent'    => $agentRoleId,
            'admin'    => $adminRoleId,
        ];

        foreach ($rolePermissions as $role => $slugs) {
            $roleId = $roleIds[$role];
            if ($roleId === null) {
                continue;
            }

            foreach ($slugs as $slug) {
                if (! isset($allPermIds[$slug])) {
                    continue;
                }

                DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                    'id'                  => (string) Str::orderedUuid(),
                    'role_id'             => $roleId,
                    'permission_id'       => $allPermIds[$slug],
                    'created_by_actor_id' => null,
                    'created_at'          => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $slugs = [
            'support.create', 'support.assign', 'support.resolve', 'support.close',
            'support.investigate', 'dispute.raise', 'dispute.approve', 'dispute.reject',
            'dispute.refund', 'dispute.escalate',
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
