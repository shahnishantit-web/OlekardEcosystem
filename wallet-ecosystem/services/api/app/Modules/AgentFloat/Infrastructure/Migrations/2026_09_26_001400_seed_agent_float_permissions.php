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
            ['slug' => 'agent_float.wallet.create',          'display_name' => 'Create Agent Float Wallet'],
            ['slug' => 'agent_float.wallet.view',            'display_name' => 'View Own Float Wallet'],
            ['slug' => 'agent_float.wallet.view_any',        'display_name' => 'View Any Agent Float Wallet (Admin)'],
            ['slug' => 'agent_float.wallet.fund',            'display_name' => 'Fund Agent Float Wallet (Admin)'],
            ['slug' => 'agent_float.limits.manage',          'display_name' => 'Set/Update Float Limits (Admin)'],
            ['slug' => 'agent_float.limits.view',            'display_name' => 'View Float Limits'],
            ['slug' => 'agent_float.cash_in',                'display_name' => 'Perform Cash-In via Agent Float'],
            ['slug' => 'agent_float.cash_out',               'display_name' => 'Perform Cash-Out via Agent Float'],
            ['slug' => 'agent_float.transactions.view',      'display_name' => 'View Own Float Transactions'],
            ['slug' => 'agent_float.transactions.view_any',  'display_name' => 'View Any Float Transactions (Admin)'],
        ];

        $now = $this->now();

        foreach ($permissions as $perm) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id'                   => (string) Str::orderedUuid(),
                'slug'                 => $perm['slug'],
                'display_name'         => $perm['display_name'],
                'description'          => null,
                'module'               => 'agent_float',
                'is_system_permission' => true,
                'deleted_at'           => null,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
        }

        $allPermIds = DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', array_column($permissions, 'slug'))
            ->pluck('id', 'slug');

        $adminRole = DB::connection('identity')->table('identity.roles')
            ->where('slug', 'admin')
            ->first(['id']);

        if ($adminRole !== null) {
            foreach ($allPermIds as $permId) {
                DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                    'id'                  => (string) Str::orderedUuid(),
                    'role_id'             => $adminRole->id,
                    'permission_id'       => $permId,
                    'created_by_actor_id' => null,
                    'created_at'          => $now,
                ]);
            }
        }

        // Assign cash_in, cash_out, wallet.view, transactions.view, limits.view to agent role
        $agentRole = DB::connection('identity')->table('identity.roles')
            ->where('slug', 'agent')
            ->first(['id']);

        if ($agentRole !== null) {
            $agentSlugs = [
                'agent_float.cash_in',
                'agent_float.cash_out',
                'agent_float.wallet.view',
                'agent_float.transactions.view',
                'agent_float.limits.view',
            ];

            foreach ($agentSlugs as $slug) {
                if (isset($allPermIds[$slug])) {
                    DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                        'id'                  => (string) Str::orderedUuid(),
                        'role_id'             => $agentRole->id,
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
            'agent_float.wallet.create', 'agent_float.wallet.view', 'agent_float.wallet.view_any',
            'agent_float.wallet.fund', 'agent_float.limits.manage', 'agent_float.limits.view',
            'agent_float.cash_in', 'agent_float.cash_out',
            'agent_float.transactions.view', 'agent_float.transactions.view_any',
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
