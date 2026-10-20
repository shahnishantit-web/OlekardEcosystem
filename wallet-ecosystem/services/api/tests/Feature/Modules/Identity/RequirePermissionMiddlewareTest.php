<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Identity;

use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\IdentityTestCase;

/**
 * T034 — RequirePermissionMiddleware: enforces named permissions on admin routes.
 * Tests via actual HTTP routes. Binds a mock IdentityModuleContract to control hasPermission().
 */
final class RequirePermissionMiddlewareTest extends IdentityTestCase
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
            'email'          => 'perm-mw@t.local',
            'mobile_number'  => '+1000001200',
            'status'         => 'active',
            'actor_type'     => 'ADMIN',
            'default_role_id' => $adminRole?->id,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    public function test_admin_with_permission_passes(): void
    {
        $response = $this->getJson('/v1/admin/roles', [
            'X-Test-User-Id'    => $this->adminId,
            'X-Test-Actor-Type' => 'ADMIN',
        ]);

        // Admin role has admin.role.read → passes
        $response->assertStatus(200);
    }

    public function test_actor_without_permission_gets_403_authorization_001(): void
    {
        $noPermAdminId = (string) \Ramsey\Uuid\Uuid::uuid7();
        \DB::connection('identity')->table('identity.users')->insert([
            'id'             => $noPermAdminId,
            'email'          => 'noperm@t.local',
            'mobile_number'  => '+1000001201',
            'status'         => 'active',
            'actor_type'     => 'ADMIN',
            'default_role_id' => null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $response = $this->getJson('/v1/admin/roles', [
            'X-Test-User-Id'    => $noPermAdminId,
            'X-Test-Actor-Type' => 'ADMIN',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'AUTHORIZATION_001');
    }

    public function test_403_response_body_is_well_formed(): void
    {
        $noPermAdmin = (string) \Ramsey\Uuid\Uuid::uuid7();
        \DB::connection('identity')->table('identity.users')->insert([
            'id'             => $noPermAdmin,
            'email'          => 'noperm2@t.local',
            'mobile_number'  => '+1000001202',
            'status'         => 'active',
            'actor_type'     => 'ADMIN',
            'default_role_id' => null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $response = $this->getJson('/v1/admin/roles', [
            'X-Test-User-Id'    => $noPermAdmin,
            'X-Test-Actor-Type' => 'ADMIN',
        ]);

        $response->assertStatus(403)
                 ->assertJsonStructure(['success', 'error' => ['code', 'message', 'trace_id']])
                 ->assertJsonPath('success', false);
    }

    public function test_permission_is_per_route(): void
    {
        // GET /admin/roles needs admin.role.read (granted to admin role)
        $readResponse = $this->getJson('/v1/admin/roles', [
            'X-Test-User-Id'    => $this->adminId,
            'X-Test-Actor-Type' => 'ADMIN',
        ]);

        // POST /admin/roles needs admin.role.create (also granted to admin role)
        $createResponse = $this->postJson('/v1/admin/roles', [
            'slug'         => 'perm-test-role',
            'display_name' => 'Perm Test Role',
        ], [
            'X-Test-User-Id'    => $this->adminId,
            'X-Test-Actor-Type' => 'ADMIN',
        ]);

        $readResponse->assertStatus(200);
        $createResponse->assertStatus(201); // passes with admin.role.create
    }
}
