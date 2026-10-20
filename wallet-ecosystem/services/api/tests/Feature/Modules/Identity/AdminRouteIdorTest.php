<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Identity;

use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\IdentityTestCase;

/**
 * T077 — IDOR/cross-tenant access: admin routes must not leak data across tenants.
 * Verifies that an admin cannot access resources that don't exist or are cross-module.
 */
final class AdminRouteIdorTest extends IdentityTestCase
{
    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

        $this->adminId = (string) \Ramsey\Uuid\Uuid::uuid7();
        $adminRole     = \DB::connection('identity')->table('identity.roles')->where('slug', 'admin')->first(['id']);

        \DB::connection('identity')->table('identity.users')->insert([
            'id'             => $this->adminId,
            'email'          => 'idor-admin@t.local',
            'mobile_number'  => '+1000000900',
            'status'         => 'active',
            'actor_type'     => 'ADMIN',
            'default_role_id' => $adminRole?->id,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    private function adminHeaders(): array
    {
        return ['X-Test-User-Id' => $this->adminId, 'X-Test-Actor-Type' => 'ADMIN'];
    }

    public function test_show_nonexistent_admin_user_returns_404_not_403(): void
    {
        $response = $this->getJson('/v1/admin/users/' . \Ramsey\Uuid\Uuid::uuid7(), $this->adminHeaders());
        // Must return 404 (not 200 or leaking info) for non-existent admin users
        $response->assertStatus(404);
    }

    public function test_show_nonexistent_role_returns_404(): void
    {
        $response = $this->getJson('/v1/admin/roles/' . \Ramsey\Uuid\Uuid::uuid7(), $this->adminHeaders());
        $response->assertStatus(404);
    }

    public function test_customer_actor_type_cannot_reach_admin_role_list(): void
    {
        $customerId = (string) \Ramsey\Uuid\Uuid::uuid7();
        \DB::connection('identity')->table('identity.users')->insert([
            'id'             => $customerId,
            'email'          => 'idor-cust@t.local',
            'mobile_number'  => '+1000000901',
            'status'         => 'active',
            'actor_type'     => 'CUSTOMER',
            'default_role_id' => null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $response = $this->getJson('/v1/admin/roles', [
            'X-Test-User-Id'    => $customerId,
            'X-Test-Actor-Type' => 'CUSTOMER',
        ]);

        $response->assertStatus(403);
    }

    public function test_agent_actor_type_cannot_reach_admin_permissions(): void
    {
        $agentId = (string) \Ramsey\Uuid\Uuid::uuid7();
        \DB::connection('identity')->table('identity.users')->insert([
            'id'             => $agentId,
            'email'          => 'idor-agent@t.local',
            'mobile_number'  => '+1000000902',
            'status'         => 'active',
            'actor_type'     => 'AGENT',
            'default_role_id' => null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $response = $this->getJson('/v1/admin/permissions', [
            'X-Test-User-Id'    => $agentId,
            'X-Test-Actor-Type' => 'AGENT',
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_without_role_cannot_read_admin_users(): void
    {
        $noRoleAdmin = (string) \Ramsey\Uuid\Uuid::uuid7();
        \DB::connection('identity')->table('identity.users')->insert([
            'id'             => $noRoleAdmin,
            'email'          => 'idor-norole@t.local',
            'mobile_number'  => '+1000000903',
            'status'         => 'active',
            'actor_type'     => 'ADMIN',
            'default_role_id' => null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $response = $this->getJson('/v1/admin/users', [
            'X-Test-User-Id'    => $noRoleAdmin,
            'X-Test-Actor-Type' => 'ADMIN',
        ]);

        $response->assertStatus(403)
                 ->assertJsonPath('error.code', 'AUTHORIZATION_001');
    }
}
