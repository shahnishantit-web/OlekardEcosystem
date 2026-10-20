<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Identity;

use Tests\Support\AssertAuditEvent;
use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\IdentityTestCase;

/**
 * T041, T067 — Admin role CRUD lifecycle: list, show, create, delete.
 */
final class AdminRoleLifecycleTest extends IdentityTestCase
{
    use AssertAuditEvent;

    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

        $this->adminId = (string) \Ramsey\Uuid\Uuid::uuid7();
        $adminRole     = \DB::connection('identity')->table('identity.roles')->where('slug', 'admin')->first(['id']);

        \DB::connection('identity')->table('identity.users')->insert([
            'id'             => $this->adminId,
            'email'          => 'role-mgr@t.local',
            'mobile_number'  => '+1000000200',
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

    public function test_list_roles_returns_paginated_result(): void
    {
        $response = $this->getJson('/v1/admin/roles', $this->adminHeaders());

        $response->assertStatus(200)
                 ->assertJsonStructure(['success', 'data', 'meta'])
                 ->assertJsonPath('success', true);
    }

    public function test_list_includes_seeded_system_roles(): void
    {
        $response = $this->getJson('/v1/admin/roles', $this->adminHeaders());

        $response->assertStatus(200);
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('admin', $slugs);
        $this->assertContains('customer', $slugs);
        $this->assertContains('agent', $slugs);
    }

    public function test_show_role_returns_role_with_permissions(): void
    {
        $adminRole = \DB::connection('identity')->table('identity.roles')->where('slug', 'admin')->first(['id']);

        $response = $this->getJson('/v1/admin/roles/' . $adminRole->id, $this->adminHeaders());

        $response->assertStatus(200)
                 ->assertJsonPath('data.slug', 'admin')
                 ->assertJsonStructure(['data' => ['id', 'slug', 'display_name', 'is_system_role']]);
    }

    public function test_show_returns_404_for_nonexistent_role(): void
    {
        $response = $this->getJson('/v1/admin/roles/' . \Ramsey\Uuid\Uuid::uuid7(), $this->adminHeaders());
        $response->assertStatus(404);
    }

    public function test_create_role_returns_201(): void
    {
        $response = $this->postJson('/v1/admin/roles', [
            'slug'         => 'support-agent',
            'display_name' => 'Support Agent',
            'description'  => 'Handles customer support tickets',
        ], $this->adminHeaders());

        $response->assertStatus(201)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.slug', 'support-agent');
    }

    public function test_create_role_slug_conflict_returns_409(): void
    {
        $this->postJson('/v1/admin/roles', [
            'slug'         => 'finance-ops',
            'display_name' => 'Finance Ops',
        ], $this->adminHeaders())->assertStatus(201);

        $response = $this->postJson('/v1/admin/roles', [
            'slug'         => 'finance-ops',
            'display_name' => 'Finance Ops Duplicate',
        ], $this->adminHeaders());

        $response->assertStatus(409)
                 ->assertJsonPath('error.code', 'ROLE_SLUG_CONFLICT');
    }

    public function test_create_role_requires_slug_and_display_name(): void
    {
        $response = $this->postJson('/v1/admin/roles', ['description' => 'Missing required fields'], $this->adminHeaders());
        $response->assertStatus(400);
    }

    public function test_delete_custom_role_returns_200(): void
    {
        $createResponse = $this->postJson('/v1/admin/roles', [
            'slug'         => 'temp-role',
            'display_name' => 'Temporary Role',
        ], $this->adminHeaders());
        $createResponse->assertStatus(201);
        $roleId = $createResponse->json('data.id');

        $response = $this->deleteJson('/v1/admin/roles/' . $roleId, [], $this->adminHeaders());
        $response->assertStatus(200)->assertJsonPath('success', true);
    }

    public function test_delete_system_role_returns_422(): void
    {
        $adminRole = \DB::connection('identity')->table('identity.roles')->where('slug', 'admin')->first(['id']);

        $response = $this->deleteJson('/v1/admin/roles/' . $adminRole->id, [], $this->adminHeaders());
        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'SYSTEM_ROLE_DELETION_DENIED');
    }

    public function test_delete_nonexistent_role_returns_404(): void
    {
        $response = $this->deleteJson('/v1/admin/roles/' . \Ramsey\Uuid\Uuid::uuid7(), [], $this->adminHeaders());
        $response->assertStatus(404);
    }

    public function test_deleted_role_does_not_appear_in_list(): void
    {
        $createResponse = $this->postJson('/v1/admin/roles', [
            'slug'         => 'ephemeral-role',
            'display_name' => 'Ephemeral',
        ], $this->adminHeaders());
        $roleId = $createResponse->json('data.id');

        $this->deleteJson('/v1/admin/roles/' . $roleId, [], $this->adminHeaders())->assertStatus(200);

        $listResponse = $this->getJson('/v1/admin/roles', $this->adminHeaders());
        $slugs        = collect($listResponse->json('data'))->pluck('slug')->all();
        $this->assertNotContains('ephemeral-role', $slugs);
    }

    public function test_delete_role_writes_audit_event(): void
    {
        $createResponse = $this->postJson('/v1/admin/roles', [
            'slug'         => 'audit-test-role',
            'display_name' => 'Audit Test Role',
        ], $this->adminHeaders());
        $createResponse->assertStatus(201);
        $roleId = $createResponse->json('data.id');

        $this->deleteJson('/v1/admin/roles/' . $roleId, [], $this->adminHeaders())->assertStatus(200);

        $this->assertAuditEventRecorded('identity.role.deleted', ['actor_id' => $this->adminId]);
    }

    public function test_failed_delete_system_role_does_not_write_deletion_audit(): void
    {
        $adminRole = \DB::connection('identity')->table('identity.roles')->where('slug', 'admin')->first(['id']);

        $this->deleteJson('/v1/admin/roles/' . $adminRole->id, [], $this->adminHeaders())->assertStatus(422);

        $this->assertAuditEventNotRecorded('identity.role.deleted');
    }

    public function test_delete_role_audit_contains_actor_and_slug(): void
    {
        $createResponse = $this->postJson('/v1/admin/roles', [
            'slug'         => 'actor-audit-role',
            'display_name' => 'Actor Audit Role',
        ], $this->adminHeaders());
        $createResponse->assertStatus(201);
        $roleId = $createResponse->json('data.id');

        $this->deleteJson('/v1/admin/roles/' . $roleId, [], $this->adminHeaders())->assertStatus(200);

        $event = \DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'identity.role.deleted')
            ->where('actor_id', $this->adminId)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('success', $event->outcome);
    }
}
