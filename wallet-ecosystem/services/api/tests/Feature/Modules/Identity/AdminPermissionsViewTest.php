<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Identity;

use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\IdentityTestCase;

/**
 * T063 — Admin permissions list: GET /v1/admin/permissions.
 */
final class AdminPermissionsViewTest extends IdentityTestCase
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
            'email'          => 'perm-viewer@t.local',
            'mobile_number'  => '+1000000600',
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

    public function test_list_permissions_returns_200(): void
    {
        $response = $this->getJson('/v1/admin/permissions', $this->adminHeaders());

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonStructure(['data', 'meta']);
    }

    public function test_list_permissions_includes_seeded_permissions(): void
    {
        $response = $this->getJson('/v1/admin/permissions', $this->adminHeaders());
        $response->assertStatus(200);

        $slugs = collect($response->json('data'))->pluck('slug')->all();

        foreach (['admin.role.read', 'admin.user.create', 'device.admin.read', 'configuration.policy.view'] as $expected) {
            $this->assertContains($expected, $slugs);
        }
    }

    public function test_list_permissions_has_15_seeded_entries(): void
    {
        $response = $this->getJson('/v1/admin/permissions?per_page=100', $this->adminHeaders());
        $response->assertStatus(200);

        $this->assertGreaterThanOrEqual(15, $response->json('meta.total'));
    }

    public function test_filter_by_module_returns_subset(): void
    {
        $response = $this->getJson('/v1/admin/permissions?module=Identity', $this->adminHeaders());
        $response->assertStatus(200);

        // All results should be from the Identity module
        $modules = collect($response->json('data'))->pluck('module')->unique()->all();
        if (!empty($modules)) {
            $this->assertSame(['Identity'], $modules);
        }
    }

    public function test_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/v1/admin/permissions');
        $response->assertStatus(401);
    }
}
