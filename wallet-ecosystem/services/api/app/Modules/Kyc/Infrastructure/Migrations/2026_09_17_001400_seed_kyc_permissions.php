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
            ['slug' => 'kyc.case.create', 'display_name' => 'Initiate a KYC case'],
            ['slug' => 'kyc.case.update', 'display_name' => 'Capture personal info; upload documents; submit; resubmit'],
            ['slug' => 'kyc.case.read',   'display_name' => 'View own KYC case status and details'],
            ['slug' => 'kyc.case.review', 'display_name' => 'Perform manual review; reject; request info; view all cases'],
            ['slug' => 'kyc.case.approve', 'display_name' => 'Approve a KYC case'],
            ['slug' => 'kyc.case.manage', 'display_name' => 'Trigger reverification; admin overrides'],
        ];

        $now = $this->now();

        foreach ($permissions as $perm) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id'                   => (string) Str::orderedUuid(),
                'slug'                 => $perm['slug'],
                'display_name'         => $perm['display_name'],
                'description'          => null,
                'module'               => 'kyc',
                'is_system_permission' => true,
                'deleted_at'           => null,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
        }

        $allPermIds = DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', array_column($permissions, 'slug'))
            ->pluck('id', 'slug');

        // Assign all 6 permissions to admin role
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

        // Assign self-service permissions (create, update, read) to customer and agent roles
        $selfServiceSlugs = ['kyc.case.create', 'kyc.case.update', 'kyc.case.read'];

        foreach (['customer', 'agent'] as $roleSlug) {
            $role = DB::connection('identity')->table('identity.roles')
                ->where('slug', $roleSlug)
                ->first(['id']);

            if ($role === null) {
                continue;
            }

            foreach ($selfServiceSlugs as $slug) {
                $permId = $allPermIds[$slug] ?? null;
                if ($permId === null) {
                    continue;
                }
                DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                    'id'                  => (string) Str::orderedUuid(),
                    'role_id'             => $role->id,
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
            'kyc.case.create', 'kyc.case.update', 'kyc.case.read',
            'kyc.case.review', 'kyc.case.approve', 'kyc.case.manage',
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
