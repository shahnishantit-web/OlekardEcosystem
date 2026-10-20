<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Identity;

use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\IdentityTestCase;

/**
 * T054 — Admin user view and list: GET /v1/admin/users, GET /v1/admin/users/{id}.
 */
final class AdminUserViewTest extends IdentityTestCase
{
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
            ['id' => $this->adminId,       'email' => 'viewer@t.local',  'mobile_number' => '+1000000400', 'status' => 'active', 'actor_type' => 'ADMIN', 'default_role_id' => $adminRole?->id, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->targetAdminId, 'email' => 'target@t.local',  'mobile_number' => '+1000000401', 'status' => 'active', 'actor_type' => 'ADMIN', 'default_role_id' => $adminRole?->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function adminHeaders(): array
    {
        return ['X-Test-User-Id' => $this->adminId, 'X-Test-Actor-Type' => 'ADMIN'];
    }

    public function test_list_admins_returns_paginated_response(): void
    {
        $response = $this->getJson('/v1/admin/users', $this->adminHeaders());

        $response->assertStatus(200)
                 ->assertJsonStructure(['success', 'data', 'meta'])
                 ->assertJsonPath('success', true);
    }

    public function test_list_admins_only_returns_admin_actor_type(): void
    {
        // Insert a customer; they must not appear in /admin/users
        $customerId = (string) \Ramsey\Uuid\Uuid::uuid7();
        \DB::connection('identity')->table('identity.users')->insert([
            'id'             => $customerId,
            'email'          => 'customer-list@t.local',
            'mobile_number'  => '+1000000402',
            'status'         => 'active',
            'actor_type'     => 'CUSTOMER',
            'default_role_id' => null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $response = $this->getJson('/v1/admin/users', $this->adminHeaders());
        $response->assertStatus(200);

        $actorTypes = collect($response->json('data'))->pluck('actor_type')->unique()->all();
        $this->assertNotContains('CUSTOMER', $actorTypes);
    }

    public function test_show_admin_user_returns_200(): void
    {
        $response = $this->getJson('/v1/admin/users/' . $this->targetAdminId, $this->adminHeaders());

        $response->assertStatus(200)
                 ->assertJsonPath('data.id', $this->targetAdminId)
                 ->assertJsonPath('data.actor_type', 'ADMIN');
    }

    public function test_show_nonexistent_admin_returns_404(): void
    {
        $response = $this->getJson('/v1/admin/users/' . \Ramsey\Uuid\Uuid::uuid7(), $this->adminHeaders());
        $response->assertStatus(404)
                 ->assertJsonPath('error.code', 'ADMIN_USER_NOT_FOUND');
    }

    public function test_show_returns_roles_in_response(): void
    {
        $response = $this->getJson('/v1/admin/users/' . $this->targetAdminId, $this->adminHeaders());

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => ['id', 'email', 'default_role', 'additional_roles']]);
    }

    public function test_list_filters_by_status(): void
    {
        // Mark target admin as inactive
        \DB::connection('identity')->table('identity.users')
            ->where('id', $this->targetAdminId)
            ->update(['status' => 'inactive', 'updated_at' => now()]);

        $response = $this->getJson('/v1/admin/users?status=inactive', $this->adminHeaders());
        $response->assertStatus(200);

        $statuses = collect($response->json('data'))->pluck('status')->unique()->all();
        $this->assertSame(['inactive'], $statuses);
    }
}
