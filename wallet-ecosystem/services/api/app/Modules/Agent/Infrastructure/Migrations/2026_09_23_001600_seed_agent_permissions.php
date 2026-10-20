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
            ['slug' => 'agent.application.create',             'display_name' => 'Create Agent Application'],
            ['slug' => 'agent.application.read',               'display_name' => 'View Own Agent Application'],
            ['slug' => 'agent.application.read.all',           'display_name' => 'View All Agent Applications (Admin)'],
            ['slug' => 'agent.approve',                        'display_name' => 'Approve Agent Application'],
            ['slug' => 'agent.reject',                         'display_name' => 'Reject Agent Application'],
            ['slug' => 'agent.suspend',                        'display_name' => 'Suspend Agent'],
            ['slug' => 'agent.reactivate',                     'display_name' => 'Reactivate Agent'],
            ['slug' => 'agent.customer.search',                'display_name' => 'Search Agent Customers'],
            ['slug' => 'agent.customer.register',              'display_name' => 'Register Customer via Agent'],
            ['slug' => 'agent.customer.kyc.initiate',          'display_name' => 'Initiate Customer KYC via Agent'],
            ['slug' => 'agent.customer.wallet.read',           'display_name' => 'View Customer Wallet (Agent)'],
            ['slug' => 'agent.customer.transactions.read',     'display_name' => 'View Customer Transactions (Agent)'],
        ];

        $now = $this->now();

        foreach ($permissions as $perm) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id'                   => (string) Str::orderedUuid(),
                'slug'                 => $perm['slug'],
                'display_name'         => $perm['display_name'],
                'description'          => null,
                'module'               => 'agent',
                'is_system_permission' => true,
                'deleted_at'           => null,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
        }

        $allPermIds = DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', array_column($permissions, 'slug'))
            ->pluck('id', 'slug');

        // Assign all 12 permissions to admin role
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
    }

    public function down(): void
    {
        $slugs = [
            'agent.application.create', 'agent.application.read', 'agent.application.read.all',
            'agent.approve', 'agent.reject', 'agent.suspend', 'agent.reactivate',
            'agent.customer.search', 'agent.customer.register', 'agent.customer.kyc.initiate',
            'agent.customer.wallet.read', 'agent.customer.transactions.read',
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
