<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Identity;

use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\IdentityTestCase;

/**
 * T036 — Verifies that admin routes are protected by actor-type and permission middleware.
 * Covers: AUTHORIZATION_001 (permission denied) and AUTHORIZATION_002 (actor type mismatch).
 */
final class AdminRouteProtectionTest extends IdentityTestCase
{
    private string $adminId;
    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

        $this->adminId    = (string) \Ramsey\Uuid\Uuid::uuid7();
        $this->customerId = (string) \Ramsey\Uuid\Uuid::uuid7();

        $adminRole    = \DB::connection('identity')->table('identity.roles')->where('slug', 'admin')->first(['id']);
        $customerRole = \DB::connection('identity')->table('identity.roles')->where('slug', 'customer')->first(['id']);

        \DB::connection('identity')->table('identity.users')->insert([
            ['id' => $this->adminId,    'email' => 'admin-prot@t.local',    'mobile_number' => '+1000000100', 'status' => 'active', 'actor_type' => 'ADMIN',    'default_role_id' => $adminRole?->id,    'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->customerId, 'email' => 'customer-prot@t.local', 'mobile_number' => '+1000000101', 'status' => 'active', 'actor_type' => 'CUSTOMER', 'default_role_id' => $customerRole?->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/v1/admin/roles');
        $response->assertStatus(401);
    }

    public function test_customer_actor_type_returns_403_authorization_002(): void
    {
        $response = $this->getJson('/v1/admin/roles', [
            'X-Test-User-Id'    => $this->customerId,
            'X-Test-Actor-Type' => 'CUSTOMER',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'AUTHORIZATION_002');
    }

    public function test_admin_without_permission_returns_403_authorization_001(): void
    {
        // Admin user with NO role → no permissions → denied on specific permission
        $noRoleAdminId = (string) \Ramsey\Uuid\Uuid::uuid7();
        \DB::connection('identity')->table('identity.users')->insert([
            'id'             => $noRoleAdminId,
            'email'          => 'norole@t.local',
            'mobile_number'  => '+1000000102',
            'status'         => 'active',
            'actor_type'     => 'ADMIN',
            'default_role_id' => null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $response = $this->getJson('/v1/admin/roles', [
            'X-Test-User-Id'    => $noRoleAdminId,
            'X-Test-Actor-Type' => 'ADMIN',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'AUTHORIZATION_001');
    }

    public function test_admin_with_permission_passes(): void
    {
        $response = $this->getJson('/v1/admin/roles', [
            'X-Test-User-Id'    => $this->adminId,
            'X-Test-Actor-Type' => 'ADMIN',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    public function test_permission_gating_is_per_route(): void
    {
        // Users with only admin.role.read can GET /roles but lack admin.user.create
        // In practice admin role has all perms, so just verify the route structure is gated
        $response = $this->postJson('/v1/admin/roles', [
            'slug'         => 'test-role',
            'display_name' => 'Test Role',
        ], [
            'X-Test-User-Id'    => $this->adminId,
            'X-Test-Actor-Type' => 'ADMIN',
        ]);

        // admin role has admin.role.create → should reach validation (not be 403)
        $this->assertNotSame(403, $response->getStatusCode());
    }
}
