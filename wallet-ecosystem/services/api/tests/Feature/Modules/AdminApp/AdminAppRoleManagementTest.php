<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AdminApp;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * @group docker
 *
 * ADM-P1-012 — Role & Permission Management backend endpoints.
 *
 * SC-001: GET  /v1/admin-app/roles               — list roles with permission slugs
 * SC-002: GET  /v1/admin-app/roles/{id}           — single role detail
 * SC-003: POST /v1/admin-app/roles                — create custom role
 * SC-004: POST /v1/admin-app/users/{id}/roles     — assign role to admin user
 * SC-005: Authorization guards                    — 403 without adminapp.roles.manage
 */
final class AdminAppRoleManagementTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['platform', 'identity'];

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seedRole(string $slug, bool $isSystem = false): string
    {
        $roleId = (string) Uuid::uuid7();
        DB::connection('identity')->table('identity.roles')->insert([
            'id'             => $roleId,
            'slug'           => $slug,
            'display_name'   => ucwords(str_replace('_', ' ', $slug)),
            'is_system_role' => $isSystem,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        return $roleId;
    }

    private function seedPermission(string $slug): string
    {
        DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
            'id'                   => (string) Uuid::uuid7(),
            'slug'                 => $slug,
            'display_name'         => $slug,
            'module'               => explode('.', $slug)[0] ?? 'adminapp',
            'is_system_permission' => true,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
        return DB::connection('identity')
            ->table('identity.permissions')
            ->where('slug', $slug)
            ->value('id');
    }

    private function attachPermission(string $roleId, string $permissionId): void
    {
        DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
            'id'            => (string) Uuid::uuid7(),
            'role_id'       => $roleId,
            'permission_id' => $permissionId,
            'created_at'    => now(),
        ]);
    }

    private function seedAdminUser(): string
    {
        $userId = (string) Uuid::uuid7();
        DB::connection('identity')->table('identity.users')->insert([
            'id'          => $userId,
            'email'       => 'testadmin-' . $userId . '@test.local',
            'status'      => 'active',
            'actor_type'  => 'ADMIN',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        return $userId;
    }

    // ── SC-001: List roles ────────────────────────────────────────────────────

    public function test_sc001_list_roles_returns_paginated_list(): void
    {
        $roleId = $this->seedRole('viewer');
        $permId = $this->seedPermission('adminapp.users.read');
        $this->attachPermission($roleId, $permId);

        $this->actingAsAdminWithPermission('adminapp.roles.manage');

        $response = $this->getJson('/v1/admin-app/roles');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [['role_id', 'role_name', 'permission_slugs', 'user_count', 'created_at']],
            'meta' => ['total', 'per_page', 'current_page', 'last_page'],
        ]);

        $roles = collect($response->json('data'));
        $viewer = $roles->firstWhere('role_id', $roleId);
        $this->assertNotNull($viewer, 'Seeded role must appear in list');
        $this->assertSame('viewer', $viewer['role_name']);
        $this->assertContains('adminapp.users.read', $viewer['permission_slugs']);
    }

    public function test_sc001_list_roles_includes_permission_slugs(): void
    {
        $roleId  = $this->seedRole('test_role_perms');
        $permId1 = $this->seedPermission('adminapp.users.read');
        $permId2 = $this->seedPermission('adminapp.users.write');
        $this->attachPermission($roleId, $permId1);
        $this->attachPermission($roleId, $permId2);

        $this->actingAsAdminWithPermission('adminapp.roles.manage');

        $response = $this->getJson('/v1/admin-app/roles');
        $response->assertStatus(200);

        $role = collect($response->json('data'))->firstWhere('role_id', $roleId);
        $this->assertCount(2, $role['permission_slugs']);
        $this->assertContains('adminapp.users.read', $role['permission_slugs']);
        $this->assertContains('adminapp.users.write', $role['permission_slugs']);
    }

    public function test_sc001_newly_created_roles_appear_first_in_the_list(): void
    {
        $olderRoleId = $this->seedRole('older_role');
        DB::connection('identity')->table('identity.roles')->where('id', $olderRoleId)
            ->update(['created_at' => now()->subDay()]);
        $newerRoleId = $this->seedRole('newer_role');
        DB::connection('identity')->table('identity.roles')->where('id', $newerRoleId)
            ->update(['created_at' => now()->addMinute()]);

        $this->actingAsAdminWithPermission('adminapp.roles.manage');
        $response = $this->getJson('/v1/admin-app/roles?per_page=1');

        $response->assertOk();
        $this->assertSame($newerRoleId, $response->json('data.0.role_id'));
    }

    public function test_sc001_list_roles_denied_without_permission(): void
    {
        $this->actingAsAdminWithPermission('adminapp.users.read');
        $this->getJson('/v1/admin-app/roles')->assertStatus(403);
    }

    // ── SC-002: Show single role ──────────────────────────────────────────────

    public function test_sc002_show_role_returns_role_with_permissions(): void
    {
        $roleId = $this->seedRole('ops_viewer');
        $permId = $this->seedPermission('adminapp.wallets.read');
        $this->attachPermission($roleId, $permId);

        $this->actingAsAdminWithPermission('adminapp.roles.manage');

        $response = $this->getJson("/v1/admin-app/roles/{$roleId}");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'role_id'   => $roleId,
                'role_name' => 'ops_viewer',
            ],
        ]);
        $this->assertContains('adminapp.wallets.read', $response->json('data.permission_slugs'));
    }

    public function test_sc002_show_role_returns_404_for_unknown_id(): void
    {
        $this->actingAsAdminWithPermission('adminapp.roles.manage');
        $unknownId = (string) Uuid::uuid7();
        $this->getJson("/v1/admin-app/roles/{$unknownId}")->assertStatus(404);
    }

    public function test_sc002_show_role_denied_without_permission(): void
    {
        $roleId = $this->seedRole('check_auth_role');
        $this->actingAsAdminWithPermission('adminapp.users.read');
        $this->getJson("/v1/admin-app/roles/{$roleId}")->assertStatus(403);
    }

    // ── SC-003: Create custom role ────────────────────────────────────────────

    public function test_sc003_create_role_returns_201_with_new_role(): void
    {
        $this->actingAsAdminWithPermission('adminapp.roles.manage');

        $response = $this->postJson('/v1/admin-app/roles', [
            'role_name'        => 'support_agent',
            'permission_slugs' => [],
        ], ['Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.role_name', 'support_agent');
        $this->assertNotEmpty($response->json('data.role_id'));
    }

    public function test_sc003_create_role_assigns_specified_permissions(): void
    {
        $this->seedPermission('adminapp.users.read');

        $this->actingAsAdminWithPermission('adminapp.roles.manage');

        $response = $this->postJson('/v1/admin-app/roles', [
            'role_name'        => 'limited_viewer',
            'permission_slugs' => ['adminapp.users.read'],
        ], ['Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(201);
        $this->assertContains('adminapp.users.read', $response->json('data.permission_slugs'));
    }

    public function test_sc003_create_role_rejects_duplicate_slug(): void
    {
        $this->seedRole('duplicate_role');

        $this->actingAsAdminWithPermission('adminapp.roles.manage');

        $response = $this->postJson('/v1/admin-app/roles', [
            'role_name'        => 'duplicate_role',
            'permission_slugs' => [],
        ], ['Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(409);
        $this->assertSame('ADMINAPP_ROLE_002', $response->json('error.code'));
    }

    public function test_sc003_create_role_rejects_missing_role_name(): void
    {
        $this->actingAsAdminWithPermission('adminapp.roles.manage');

        $this->postJson('/v1/admin-app/roles', [
            'permission_slugs' => [],
        ], ['Idempotency-Key' => (string) Uuid::uuid7()])->assertStatus(422);
    }

    public function test_sc003_create_role_rejects_invalid_role_name_format(): void
    {
        $this->actingAsAdminWithPermission('adminapp.roles.manage');

        $this->postJson('/v1/admin-app/roles', [
            'role_name' => 'Invalid Role Name!',
        ], ['Idempotency-Key' => (string) Uuid::uuid7()])->assertStatus(422);
    }

    public function test_sc003_create_role_requires_idempotency_key(): void
    {
        $this->actingAsAdminWithPermission('adminapp.roles.manage');

        $this->postJson('/v1/admin-app/roles', [
            'role_name' => 'no_idem_key_role',
        ])->assertStatus(400);
    }

    public function test_sc003_create_role_denied_without_permission(): void
    {
        $this->actingAsAdminWithPermission('adminapp.users.read');

        $this->postJson('/v1/admin-app/roles', [
            'role_name' => 'unauthorized_role',
        ], ['Idempotency-Key' => (string) Uuid::uuid7()])->assertStatus(403);
    }

    // ── SC-004: Assign role to admin user ─────────────────────────────────────

    public function test_sc004_assign_role_returns_200_with_assignment(): void
    {
        $targetUserId = $this->seedAdminUser();
        $roleId       = $this->seedRole('assignable_role');

        $this->actingAsAdminWithPermission('adminapp.roles.manage');

        $response = $this->postJson("/v1/admin-app/users/{$targetUserId}/roles", [
            'role_id' => $roleId,
        ], ['Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'user_id' => $targetUserId,
                'role_id' => $roleId,
            ],
        ]);
        $this->assertNotEmpty($response->json('data.assigned_at'));
    }

    public function test_sc004_assign_role_persists_to_user_roles_table(): void
    {
        $targetUserId = $this->seedAdminUser();
        $roleId       = $this->seedRole('persisted_role');

        $this->actingAsAdminWithPermission('adminapp.roles.manage');

        $this->postJson("/v1/admin-app/users/{$targetUserId}/roles", [
            'role_id' => $roleId,
        ], ['Idempotency-Key' => (string) Uuid::uuid7()])->assertStatus(200);

        $assigned = DB::connection('identity')
            ->table('identity.user_roles')
            ->where('user_id', $targetUserId)
            ->where('role_id', $roleId)
            ->exists();

        $this->assertTrue($assigned, 'Role assignment must be persisted in identity.user_roles');
    }

    public function test_sc004_assign_role_returns_404_for_unknown_user(): void
    {
        $roleId = $this->seedRole('role_for_missing_user');

        $this->actingAsAdminWithPermission('adminapp.roles.manage');

        $this->postJson('/v1/admin-app/users/' . (string) Uuid::uuid7() . '/roles', [
            'role_id' => $roleId,
        ], ['Idempotency-Key' => (string) Uuid::uuid7()])->assertStatus(404);
    }

    public function test_sc004_assign_role_returns_404_for_unknown_role(): void
    {
        $targetUserId = $this->seedAdminUser();

        $this->actingAsAdminWithPermission('adminapp.roles.manage');

        $this->postJson("/v1/admin-app/users/{$targetUserId}/roles", [
            'role_id' => (string) Uuid::uuid7(),
        ], ['Idempotency-Key' => (string) Uuid::uuid7()])->assertStatus(404);
    }

    public function test_sc004_assign_role_returns_409_when_already_assigned(): void
    {
        $targetUserId = $this->seedAdminUser();
        $roleId       = $this->seedRole('double_assign_role');

        $this->actingAsAdminWithPermission('adminapp.roles.manage');

        $this->postJson("/v1/admin-app/users/{$targetUserId}/roles", [
            'role_id' => $roleId,
        ], ['Idempotency-Key' => (string) Uuid::uuid7()])->assertStatus(200);

        $this->postJson("/v1/admin-app/users/{$targetUserId}/roles", [
            'role_id' => $roleId,
        ], ['Idempotency-Key' => (string) Uuid::uuid7()])->assertStatus(409);
    }

    public function test_sc004_assign_role_requires_idempotency_key(): void
    {
        $targetUserId = $this->seedAdminUser();
        $roleId       = $this->seedRole('no_idem_assign_role');

        $this->actingAsAdminWithPermission('adminapp.roles.manage');

        $this->postJson("/v1/admin-app/users/{$targetUserId}/roles", [
            'role_id' => $roleId,
        ])->assertStatus(400);
    }

    public function test_sc004_assign_role_denied_without_permission(): void
    {
        $targetUserId = $this->seedAdminUser();
        $roleId       = $this->seedRole('denied_assign_role');

        $this->actingAsAdminWithPermission('adminapp.users.read');

        $this->postJson("/v1/admin-app/users/{$targetUserId}/roles", [
            'role_id' => $roleId,
        ], ['Idempotency-Key' => (string) Uuid::uuid7()])->assertStatus(403);
    }

    // ── SC-005: Unauthenticated guard ─────────────────────────────────────────

    public function test_sc005_list_roles_requires_authentication(): void
    {
        $this->getJson('/v1/admin-app/roles')->assertStatus(401);
    }

    public function test_sc005_create_role_requires_authentication(): void
    {
        $this->postJson('/v1/admin-app/roles', [
            'role_name' => 'noauth_role',
        ])->assertStatus(401);
    }
}
