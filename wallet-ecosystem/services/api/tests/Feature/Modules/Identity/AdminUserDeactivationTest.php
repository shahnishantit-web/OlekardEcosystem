<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Identity;

use Tests\Support\AssertAuditEvent;
use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\IdentityTestCase;

/**
 * T059 — Admin user deactivation: PATCH /v1/admin/users/{id}/status.
 */
final class AdminUserDeactivationTest extends IdentityTestCase
{
    use AssertAuditEvent;

    private string $adminId;
    private string $targetAdminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

        $this->adminId       = (string) \Ramsey\Uuid\Uuid::uuid7();
        $this->targetAdminId = (string) \Ramsey\Uuid\Uuid::uuid7();

        $adminRole = \DB::connection('identity')->table('identity.roles')->where('slug', 'admin')->first(['id']);

        \DB::connection('identity')->table('identity.users')->insert([
            ['id' => $this->adminId,       'email' => 'deact-actor@t.local',  'mobile_number' => '+1000000500', 'status' => 'active', 'actor_type' => 'ADMIN', 'default_role_id' => $adminRole?->id, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->targetAdminId, 'email' => 'deact-target@t.local', 'mobile_number' => '+1000000501', 'status' => 'active', 'actor_type' => 'ADMIN', 'default_role_id' => $adminRole?->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function adminHeaders(): array
    {
        return ['X-Test-User-Id' => $this->adminId, 'X-Test-Actor-Type' => 'ADMIN'];
    }

    public function test_deactivate_admin_returns_200(): void
    {
        $response = $this->patchJson('/v1/admin/users/' . $this->targetAdminId . '/status', [
            'status' => 'inactive',
        ], $this->adminHeaders());

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.sessions_revoked', 0);
    }

    public function test_deactivated_admin_status_is_updated_in_db(): void
    {
        $this->patchJson('/v1/admin/users/' . $this->targetAdminId . '/status', [
            'status' => 'inactive',
        ], $this->adminHeaders())->assertStatus(200);

        $user = \DB::connection('identity')
            ->table('identity.users')
            ->where('id', $this->targetAdminId)
            ->first(['status']);

        $this->assertSame('inactive', $user->status);
    }

    public function test_self_deactivate_returns_422(): void
    {
        $response = $this->patchJson('/v1/admin/users/' . $this->adminId . '/status', [
            'status' => 'inactive',
        ], $this->adminHeaders());

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'ADMIN_SELF_DEACTIVATE');
    }

    public function test_deactivate_nonexistent_admin_returns_404(): void
    {
        $response = $this->patchJson('/v1/admin/users/' . \Ramsey\Uuid\Uuid::uuid7() . '/status', [
            'status' => 'inactive',
        ], $this->adminHeaders());

        $response->assertStatus(404)
                 ->assertJsonPath('error.code', 'ADMIN_USER_NOT_FOUND');
    }

    public function test_deactivation_requires_status_field(): void
    {
        $response = $this->patchJson('/v1/admin/users/' . $this->targetAdminId . '/status', [], $this->adminHeaders());
        $response->assertStatus(400);
    }

    public function test_reactivate_inactive_admin_sets_status_active(): void
    {
        // Deactivate first
        $this->patchJson('/v1/admin/users/' . $this->targetAdminId . '/status', [
            'status' => 'inactive',
        ], $this->adminHeaders())->assertStatus(200);

        // Reactivate
        $response = $this->patchJson('/v1/admin/users/' . $this->targetAdminId . '/status', [
            'status' => 'active',
        ], $this->adminHeaders());

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.sessions_revoked', 0);

        $status = \DB::connection('identity')
            ->table('identity.users')
            ->where('id', $this->targetAdminId)
            ->value('status');

        $this->assertSame('active', $status);
    }

    public function test_reactivate_already_active_admin_is_noop(): void
    {
        // User is already active — same-status request must be accepted as a no-op (FR-E04-024)
        $response = $this->patchJson('/v1/admin/users/' . $this->targetAdminId . '/status', [
            'status' => 'active',
        ], $this->adminHeaders());

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.sessions_revoked', 0);
    }

    public function test_reactivate_nonexistent_admin_returns_404(): void
    {
        $response = $this->patchJson('/v1/admin/users/' . \Ramsey\Uuid\Uuid::uuid7() . '/status', [
            'status' => 'active',
        ], $this->adminHeaders());

        $response->assertStatus(404)
                 ->assertJsonPath('error.code', 'ADMIN_USER_NOT_FOUND');
    }

    public function test_deactivation_writes_audit_event(): void
    {
        $this->patchJson('/v1/admin/users/' . $this->targetAdminId . '/status', [
            'status' => 'inactive',
        ], $this->adminHeaders())->assertStatus(200);

        $this->assertAuditEventRecorded('identity.admin_user.deactivated', ['actor_id' => $this->adminId]);
    }

    public function test_deactivate_without_permission_returns_403(): void
    {
        // ADMIN actor with no roles → PermissionResolverService returns [] → 403 (SC-E04-005, FR-E04-039)
        $noPermId = (string) \Ramsey\Uuid\Uuid::uuid7();
        \DB::connection('identity')->table('identity.users')->insert([
            'id'              => $noPermId,
            'email'           => 'noperm-deact@t.local',
            'mobile_number'   => '+1000000599',
            'status'          => 'active',
            'actor_type'      => 'ADMIN',
            'default_role_id' => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $response = $this->patchJson(
            '/v1/admin/users/' . $this->targetAdminId . '/status',
            ['status' => 'inactive'],
            ['X-Test-User-Id' => $noPermId, 'X-Test-Actor-Type' => 'ADMIN'],
        );

        $response->assertStatus(403)
                 ->assertJsonPath('error.code', 'AUTHORIZATION_001');
    }
}
