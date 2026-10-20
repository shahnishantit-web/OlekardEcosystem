<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Identity;

use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityTestCase;

/**
 * T080 — SC-E04-006: Seed migration idempotency.
 *
 * Verifies that re-running the E04 seed migrations does not create duplicate
 * roles, permissions, role-permission assignments, or configuration values.
 *
 * Strategy: the seed migrations have already committed their data before this
 * test starts (DatabaseTransactions wraps only the test body, not migration setup).
 * We call each migration's up() a second time and assert counts are unchanged.
 */
final class SeedIdempotencyTest extends IdentityTestCase
{
    // -------------------------------------------------------------------------
    // Roles seed (migration 006000)
    // -------------------------------------------------------------------------

    public function test_roles_seed_produces_exactly_three_system_roles(): void
    {
        $count = DB::connection('identity')
            ->table('identity.roles')
            ->whereIn('slug', ['customer', 'agent', 'admin'])
            ->where('is_system_role', true)
            ->count();

        $this->assertSame(3, $count, 'Expected exactly 3 seeded system roles.');
    }

    public function test_roles_seed_is_idempotent(): void
    {
        $before = DB::connection('identity')
            ->table('identity.roles')
            ->whereIn('slug', ['customer', 'agent', 'admin'])
            ->count();

        $this->assertSame(3, $before);

        // Re-run the seed migration's up() method.
        $migration = require base_path('app/Modules/Identity/Infrastructure/Migrations/2026_09_09_006000_seed_identity_roles.php');
        $migration->up();

        $after = DB::connection('identity')
            ->table('identity.roles')
            ->whereIn('slug', ['customer', 'agent', 'admin'])
            ->count();

        $this->assertSame(3, $after, 'Re-running roles seed must not create duplicate roles.');
    }

    public function test_roles_seed_system_flag_and_slugs_are_correct(): void
    {
        $roles = DB::connection('identity')
            ->table('identity.roles')
            ->whereIn('slug', ['customer', 'agent', 'admin'])
            ->get(['slug', 'is_system_role'])
            ->keyBy('slug');

        $this->assertCount(3, $roles);

        foreach (['customer', 'agent', 'admin'] as $slug) {
            $this->assertTrue(
                (bool) $roles[$slug]->is_system_role,
                "Role '{$slug}' must have is_system_role = true.",
            );
        }
    }

    // -------------------------------------------------------------------------
    // Permissions seed (migration 007000)
    // -------------------------------------------------------------------------

    public function test_permissions_seed_produces_exactly_fifteen_permissions(): void
    {
        $expectedSlugs = [
            'admin.user.create', 'admin.user.read', 'admin.user.deactivate',
            'admin.role.read', 'admin.role.create', 'admin.role.assign', 'admin.role.manage',
            'admin.permission.read', 'configuration.policy.view', 'configuration.policy.update',
            'session.admin.revoke', 'device.admin.read', 'device.admin.block',
            'device.admin.remove', 'device.admin.unblock',
        ];

        $count = DB::connection('identity')
            ->table('identity.permissions')
            ->whereIn('slug', $expectedSlugs)
            ->count();

        $this->assertSame(15, $count, 'Expected exactly 15 seeded permissions from FR-E04-007 catalogue.');
    }

    public function test_permissions_seed_is_idempotent(): void
    {
        $before = DB::connection('identity')
            ->table('identity.permissions')
            ->where('is_system_permission', true)
            ->count();

        $this->assertGreaterThanOrEqual(15, $before);

        $migration = require base_path('app/Modules/Identity/Infrastructure/Migrations/2026_09_09_007000_seed_identity_permissions_and_role_assignments.php');
        $migration->up();

        $after = DB::connection('identity')
            ->table('identity.permissions')
            ->where('is_system_permission', true)
            ->count();

        $this->assertSame($before, $after, 'Re-running permissions seed must not create duplicate permission records.');
    }

    // -------------------------------------------------------------------------
    // Role-permission assignments seed (migration 007000)
    // -------------------------------------------------------------------------

    public function test_admin_role_has_all_fifteen_permissions_assigned(): void
    {
        $adminRole = DB::connection('identity')
            ->table('identity.roles')
            ->where('slug', 'admin')
            ->first(['id']);

        $this->assertNotNull($adminRole, 'Admin role must exist after seed migration.');

        $count = DB::connection('identity')
            ->table('identity.role_permissions')
            ->where('role_id', $adminRole->id)
            ->count();

        $this->assertSame(15, $count, 'Admin role must have exactly 15 permissions assigned (FR-E04-007).');
    }

    public function test_role_permission_assignments_seed_is_idempotent(): void
    {
        $adminRole = DB::connection('identity')
            ->table('identity.roles')
            ->where('slug', 'admin')
            ->first(['id']);

        $before = DB::connection('identity')
            ->table('identity.role_permissions')
            ->where('role_id', $adminRole->id)
            ->count();

        $this->assertSame(15, $before);

        $migration = require base_path('app/Modules/Identity/Infrastructure/Migrations/2026_09_09_007000_seed_identity_permissions_and_role_assignments.php');
        $migration->up();

        $after = DB::connection('identity')
            ->table('identity.role_permissions')
            ->where('role_id', $adminRole->id)
            ->count();

        $this->assertSame(15, $after, 'Re-running role-permission seed must not create duplicate assignments.');
    }

    public function test_customer_and_agent_roles_have_no_seeded_permissions(): void
    {
        $roles = DB::connection('identity')
            ->table('identity.roles')
            ->whereIn('slug', ['customer', 'agent'])
            ->pluck('id');

        $count = DB::connection('identity')
            ->table('identity.role_permissions')
            ->whereIn('role_id', $roles->all())
            ->count();

        $this->assertSame(0, $count, 'Customer and agent roles must have zero seeded permissions (TBD-E04-003).');
    }

    // -------------------------------------------------------------------------
    // Platform configuration seed (migration 008000)
    // -------------------------------------------------------------------------

    public function test_permission_cache_ttl_config_exists_with_correct_default(): void
    {
        $row = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'auth.permission_cache_ttl')
            ->first(['config_value', 'value_type', 'status']);

        $this->assertNotNull($row, 'auth.permission_cache_ttl configuration value must exist after seed.');
        $this->assertSame('300', $row->config_value, 'Default cache TTL must be 300 seconds (FR-E04-056).');
        $this->assertSame('number', $row->value_type);
        $this->assertSame('active', $row->status);
    }

    public function test_permission_cache_ttl_config_seed_is_idempotent(): void
    {
        $before = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'auth.permission_cache_ttl')
            ->count();

        $this->assertSame(1, $before);

        $migration = require base_path('app/Modules/Identity/Infrastructure/Migrations/2026_09_09_008000_seed_permission_cache_ttl_configuration.php');
        $migration->up();

        $after = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'auth.permission_cache_ttl')
            ->count();

        $this->assertSame(1, $after, 'Re-running config seed must not create a duplicate auth.permission_cache_ttl entry.');
    }
}
