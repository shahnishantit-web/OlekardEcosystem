<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Identity;

use Tests\Support\AssertAuditEvent;
use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\IdentityTestCase;

/**
 * T071 — Role-permission assignment: POST/DELETE /v1/admin/roles/{id}/permissions/{permissionId}.
 */
final class AdminRolePermissionTest extends IdentityTestCase
{
    use AssertAuditEvent;

    private string $adminId;
    private string $testRoleId;
    private string $permissionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

        $this->adminId = (string) \Ramsey\Uuid\Uuid::uuid7();
        $adminRole     = \DB::connection('identity')->table('identity.roles')->where('slug', 'admin')->first(['id']);

        \DB::connection('identity')->table('identity.users')->insert([
            'id'             => $this->adminId,
            'email'          => 'rp-mgr@t.local',
            'mobile_number'  => '+1000000700',
            'status'         => 'active',
            'actor_type'     => 'ADMIN',
            'default_role_id' => $adminRole?->id,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Create a custom non-system role
        $this->testRoleId = (string) \Ramsey\Uuid\Uuid::uuid7();
        \DB::connection('identity')->table('identity.roles')->insert([
            'id'             => $this->testRoleId,
            'slug'           => 'test-role-perm',
            'display_name'   => 'Test Role Perm',
            'is_system_role' => false,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Pick one permission to assign
        $permission = \DB::connection('identity')
            ->table('identity.permissions')
            ->where('slug', 'admin.permission.read')
            ->first(['id']);

        $this->permissionId = $permission->id;
    }

    private function adminHeaders(): array
    {
        return ['X-Test-User-Id' => $this->adminId, 'X-Test-Actor-Type' => 'ADMIN'];
    }

    public function test_assign_permission_to_role_returns_200(): void
    {
        $response = $this->postJson(
            '/v1/admin/roles/' . $this->testRoleId . '/permissions',
            ['permission_id' => $this->permissionId],
            $this->adminHeaders(),
        );

        $response->assertStatus(200)
                 ->assertJsonPath('success', true);
    }

    public function test_assign_already_assigned_permission_returns_409(): void
    {
        $this->postJson(
            '/v1/admin/roles/' . $this->testRoleId . '/permissions',
            ['permission_id' => $this->permissionId],
            $this->adminHeaders(),
        )->assertStatus(200);

        $response = $this->postJson(
            '/v1/admin/roles/' . $this->testRoleId . '/permissions',
            ['permission_id' => $this->permissionId],
            $this->adminHeaders(),
        );

        $response->assertStatus(409)
                 ->assertJsonPath('error.code', 'PERMISSION_ALREADY_ON_ROLE');
    }

    public function test_remove_permission_from_role_returns_200(): void
    {
        $this->postJson(
            '/v1/admin/roles/' . $this->testRoleId . '/permissions',
            ['permission_id' => $this->permissionId],
            $this->adminHeaders(),
        )->assertStatus(200);

        $response = $this->deleteJson(
            '/v1/admin/roles/' . $this->testRoleId . '/permissions/' . $this->permissionId,
            [],
            $this->adminHeaders(),
        );

        $response->assertStatus(200)
                 ->assertJsonPath('success', true);
    }

    public function test_remove_unassigned_permission_returns_404(): void
    {
        $response = $this->deleteJson(
            '/v1/admin/roles/' . $this->testRoleId . '/permissions/' . $this->permissionId,
            [],
            $this->adminHeaders(),
        );

        $response->assertStatus(404)
                 ->assertJsonPath('error.code', 'PERMISSION_NOT_ON_ROLE');
    }

    public function test_assign_permission_to_nonexistent_role_returns_404(): void
    {
        $response = $this->postJson(
            '/v1/admin/roles/' . \Ramsey\Uuid\Uuid::uuid7() . '/permissions',
            ['permission_id' => $this->permissionId],
            $this->adminHeaders(),
        );

        $response->assertStatus(404);
    }

    public function test_assign_nonexistent_permission_returns_404(): void
    {
        $response = $this->postJson(
            '/v1/admin/roles/' . $this->testRoleId . '/permissions',
            ['permission_id' => (string) \Ramsey\Uuid\Uuid::uuid7()],
            $this->adminHeaders(),
        );

        $response->assertStatus(404);
    }

    public function test_assign_permission_writes_audit_event(): void
    {
        $this->postJson(
            '/v1/admin/roles/' . $this->testRoleId . '/permissions',
            ['permission_id' => $this->permissionId],
            $this->adminHeaders(),
        )->assertStatus(200);

        $this->assertAuditEventRecorded('identity.role.permission.assigned', ['actor_id' => $this->adminId]);
    }

    public function test_remove_permission_writes_audit_event(): void
    {
        $this->postJson(
            '/v1/admin/roles/' . $this->testRoleId . '/permissions',
            ['permission_id' => $this->permissionId],
            $this->adminHeaders(),
        )->assertStatus(200);

        $this->deleteJson(
            '/v1/admin/roles/' . $this->testRoleId . '/permissions/' . $this->permissionId,
            [],
            $this->adminHeaders(),
        )->assertStatus(200);

        $this->assertAuditEventRecorded('identity.role.permission.removed', ['actor_id' => $this->adminId]);
    }

    public function test_failed_remove_does_not_write_audit_event(): void
    {
        $this->deleteJson(
            '/v1/admin/roles/' . $this->testRoleId . '/permissions/' . $this->permissionId,
            [],
            $this->adminHeaders(),
        )->assertStatus(404);

        $this->assertAuditEventNotRecorded('identity.role.permission.removed');
    }
}
