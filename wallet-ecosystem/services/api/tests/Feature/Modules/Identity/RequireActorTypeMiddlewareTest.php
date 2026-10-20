<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Identity;

use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\IdentityTestCase;

/**
 * T035 — RequireActorTypeMiddleware: enforces actor type on admin routes.
 * Tests via actual HTTP routes so the response factory and routing infrastructure are available.
 */
final class RequireActorTypeMiddlewareTest extends IdentityTestCase
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
            ['id' => $this->adminId,    'email' => 'mw-admin@t.local',    'mobile_number' => '+1000001100', 'status' => 'active', 'actor_type' => 'ADMIN',    'default_role_id' => $adminRole?->id,    'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->customerId, 'email' => 'mw-customer@t.local', 'mobile_number' => '+1000001101', 'status' => 'active', 'actor_type' => 'CUSTOMER', 'default_role_id' => $customerRole?->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_admin_actor_type_passes_admin_route(): void
    {
        $response = $this->getJson('/v1/admin/roles', [
            'X-Test-User-Id'    => $this->adminId,
            'X-Test-Actor-Type' => 'ADMIN',
        ]);

        $response->assertStatus(200);
    }

    public function test_admin_actor_type_case_insensitive(): void
    {
        $response = $this->getJson('/v1/admin/roles', [
            'X-Test-User-Id'    => $this->adminId,
            'X-Test-Actor-Type' => 'admin',
        ]);

        // Case-insensitive — 'admin' matches 'ADMIN' requirement
        $response->assertStatus(200);
    }

    public function test_customer_actor_type_returns_403_authorization_002(): void
    {
        $response = $this->getJson('/v1/admin/roles', [
            'X-Test-User-Id'    => $this->customerId,
            'X-Test-Actor-Type' => 'CUSTOMER',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'AUTHORIZATION_002');
        $response->assertJsonPath('success', false);
    }

    public function test_missing_actor_type_returns_403_authorization_002(): void
    {
        // FakeIdentityAuthMiddleware defaults to 'CUSTOMER' when X-Test-Actor-Type not set
        $response = $this->getJson('/v1/admin/roles', [
            'X-Test-User-Id' => $this->customerId,
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'AUTHORIZATION_002');
    }

    public function test_response_includes_correlation_id_header_on_403(): void
    {
        $corrId   = 'test-corr-' . uniqid();
        $response = $this->getJson('/v1/admin/roles', [
            'X-Test-User-Id'    => $this->customerId,
            'X-Test-Actor-Type' => 'CUSTOMER',
            'X-Correlation-ID'  => $corrId,
        ]);

        $response->assertStatus(403);
        $this->assertSame($corrId, $response->headers->get('X-Correlation-ID'));
    }
}
