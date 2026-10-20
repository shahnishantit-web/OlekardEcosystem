<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Identity;

use Tests\Support\AssertAuditEvent;
use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\IdentityTestCase;

/**
 * T076 — User-role assignment: GET/POST/DELETE /v1/admin/users/{id}/roles.
 */
final class AdminRoleAssignmentTest extends IdentityTestCase
{
    use AssertAuditEvent;

    private string $adminId;
    private string $targetAdminId;
    private string $extraRoleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

        $this->adminId       = (string) \Ramsey\Uuid\Uuid::uuid7();
        $this->targetAdminId = (string) \Ramsey\Uuid\Uuid::uuid7();

        $adminRole = \DB::connection('identity')->table('identity.roles')->where('slug', 'admin')->first(['id']);

        \DB::connection('identity')->table('identity.users')->insert([
            ['id' => $this->adminId,       'email' => 'ra-actor@t.local',  'mobile_number' => '+1000000800', 'status' => 'active', 'actor_type' => 'ADMIN', 'default_role_id' => $adminRole?->id, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->targetAdminId, 'email' => 'ra-target@t.local', 'mobile_number' => '+1000000801', 'status' => 'active', 'actor_type' => 'ADMIN', 'default_role_id' => $adminRole?->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Create a custom role for assignment tests
        $this->extraRoleId = (string) \Ramsey\Uuid\Uuid::uuid7();
        \DB::connection('identity')->table('identity.roles')->insert([
            'id'             => $this->extraRoleId,
            'slug'           => 'extra-role',
            'display_name'   => 'Extra Role',
            'is_system_role' => false,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    private function adminHeaders(): array
    {
        return ['X-Test-User-Id' => $this->adminId, 'X-Test-Actor-Type' => 'ADMIN'];
    }

    public function test_get_user_roles_returns_200(): void
    {
        $response = $this->getJson('/v1/admin/users/' . $this->targetAdminId . '/roles', $this->adminHeaders());

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonStructure(['data' => ['default_role', 'additional_roles']]);
    }

    public function test_get_roles_for_nonexistent_user_returns_404(): void
    {
        $response = $this->getJson('/v1/admin/users/' . \Ramsey\Uuid\Uuid::uuid7() . '/roles', $this->adminHeaders());
        $response->assertStatus(404)
                 ->assertJsonPath('error.code', 'ADMIN_USER_NOT_FOUND');
    }

    public function test_assign_role_to_user_returns_200(): void
    {
        $response = $this->postJson('/v1/admin/users/' . $this->targetAdminId . '/roles', [
            'role_id' => $this->extraRoleId,
        ], $this->adminHeaders());

        $response->assertStatus(200)
                 ->assertJsonPath('success', true);
    }

    public function test_assign_already_assigned_role_returns_409(): void
    {
        $this->postJson('/v1/admin/users/' . $this->targetAdminId . '/roles', [
            'role_id' => $this->extraRoleId,
        ], $this->adminHeaders())->assertStatus(200);

        $response = $this->postJson('/v1/admin/users/' . $this->targetAdminId . '/roles', [
            'role_id' => $this->extraRoleId,
        ], $this->adminHeaders());

        $response->assertStatus(409)
                 ->assertJsonPath('error.code', 'ROLE_ALREADY_ASSIGNED');
    }

    public function test_assign_nonexistent_role_returns_404(): void
    {
        $response = $this->postJson('/v1/admin/users/' . $this->targetAdminId . '/roles', [
            'role_id' => (string) \Ramsey\Uuid\Uuid::uuid7(),
        ], $this->adminHeaders());

        $response->assertStatus(404)
                 ->assertJsonPath('error.code', 'ROLE_NOT_FOUND');
    }

    public function test_remove_role_from_user_returns_200(): void
    {
        $this->postJson('/v1/admin/users/' . $this->targetAdminId . '/roles', [
            'role_id' => $this->extraRoleId,
        ], $this->adminHeaders())->assertStatus(200);

        $response = $this->deleteJson(
            '/v1/admin/users/' . $this->targetAdminId . '/roles/' . $this->extraRoleId,
            [],
            $this->adminHeaders(),
        );

        $response->assertStatus(200)
                 ->assertJsonPath('success', true);
    }

    public function test_remove_unassigned_role_returns_404(): void
    {
        $response = $this->deleteJson(
            '/v1/admin/users/' . $this->targetAdminId . '/roles/' . $this->extraRoleId,
            [],
            $this->adminHeaders(),
        );

        $response->assertStatus(404)
                 ->assertJsonPath('error.code', 'ROLE_NOT_ASSIGNED');
    }

    public function test_assignment_is_reflected_in_get_roles(): void
    {
        $this->postJson('/v1/admin/users/' . $this->targetAdminId . '/roles', [
            'role_id' => $this->extraRoleId,
        ], $this->adminHeaders())->assertStatus(200);

        $response = $this->getJson('/v1/admin/users/' . $this->targetAdminId . '/roles', $this->adminHeaders());
        $response->assertStatus(200);

        $additionalRoleIds = collect($response->json('data.additional_roles'))->pluck('id')->all();
        $this->assertContains($this->extraRoleId, $additionalRoleIds);
    }

    public function test_assign_role_writes_audit_event(): void
    {
        $this->postJson('/v1/admin/users/' . $this->targetAdminId . '/roles', [
            'role_id' => $this->extraRoleId,
        ], $this->adminHeaders())->assertStatus(200);

        $this->assertAuditEventRecorded('identity.user.role.assigned', ['actor_id' => $this->adminId]);
    }

    public function test_remove_role_writes_audit_event(): void
    {
        $this->postJson('/v1/admin/users/' . $this->targetAdminId . '/roles', [
            'role_id' => $this->extraRoleId,
        ], $this->adminHeaders())->assertStatus(200);

        $this->deleteJson(
            '/v1/admin/users/' . $this->targetAdminId . '/roles/' . $this->extraRoleId,
            [],
            $this->adminHeaders(),
        )->assertStatus(200);

        $this->assertAuditEventRecorded('identity.user.role.removed', ['actor_id' => $this->adminId]);
    }

    public function test_failed_remove_role_does_not_write_audit_event(): void
    {
        $this->deleteJson(
            '/v1/admin/users/' . $this->targetAdminId . '/roles/' . $this->extraRoleId,
            [],
            $this->adminHeaders(),
        )->assertStatus(404);

        $this->assertAuditEventNotRecorded('identity.user.role.removed');
    }

    public function test_assign_default_role_returns_409_role_already_assigned_ec11(): void
    {
        // EC-11: the target admin's default_role_id is the admin role (set in setUp).
        // That role is NOT in identity.user_roles, so the DB unique constraint would not catch this.
        // AdminUserService::assignRole() must explicitly check default_role_id (FR-E04-034, EC-11).
        $adminRole = \DB::connection('identity')
            ->table('identity.roles')
            ->where('slug', 'admin')
            ->first(['id']);

        $response = $this->postJson('/v1/admin/users/' . $this->targetAdminId . '/roles', [
            'role_id' => $adminRole->id,
        ], $this->adminHeaders());

        $response->assertStatus(409)
                 ->assertJsonPath('error.code', 'ROLE_ALREADY_ASSIGNED');
    }

    public function test_permission_cache_is_invalidated_after_role_assignment(): void
    {
        // Pre-seed a stale cache entry to simulate a warm Redis cache (SEC-E04-007)
        $cacheKey = 'identity:permissions:' . $this->targetAdminId;
        $this->identityRedis->setex($cacheKey, 300, json_encode(['admin.role.read']));
        $this->assertSame(1, $this->identityRedis->exists($cacheKey));

        $this->postJson('/v1/admin/users/' . $this->targetAdminId . '/roles', [
            'role_id' => $this->extraRoleId,
        ], $this->adminHeaders())->assertStatus(200);

        // AdminUserService::assignRole() calls permissionResolver->invalidateCache($userId) synchronously
        $this->assertSame(0, $this->identityRedis->exists($cacheKey));
    }
}
