<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Identity;

use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\IdentityTestCase;

/**
 * T048 — Admin user creation: POST /v1/admin/users.
 */
final class AdminUserCreationTest extends IdentityTestCase
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
            'email'          => 'creator@t.local',
            'mobile_number'  => '+1000000300',
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

    public function test_create_admin_returns_201(): void
    {
        $response = $this->postJson('/v1/admin/users', [
            'email'        => 'newadmin@olekard.local',
            'display_name' => 'New Admin',
            'password'     => 'Secure#Pass1',
        ], $this->adminHeaders());

        $response->assertStatus(201)
                 ->assertJsonPath('success', true)
                 ->assertJsonStructure(['data' => ['id', 'email', 'actor_type', 'status']]);
    }

    public function test_create_admin_sets_actor_type_admin(): void
    {
        $response = $this->postJson('/v1/admin/users', [
            'email'        => 'admintype@olekard.local',
            'display_name' => 'Actor Type Test',
            'password'     => 'Secure#Pass1',
        ], $this->adminHeaders());

        $response->assertStatus(201)
                 ->assertJsonPath('data.actor_type', 'ADMIN');
    }

    public function test_duplicate_email_returns_409(): void
    {
        $payload = [
            'email'        => 'dup-admin@olekard.local',
            'display_name' => 'Duplicate Admin',
            'password'     => 'Secure#Pass1',
        ];

        $this->postJson('/v1/admin/users', $payload, $this->adminHeaders())->assertStatus(201);

        $response = $this->postJson('/v1/admin/users', $payload, $this->adminHeaders());
        $response->assertStatus(409)
                 ->assertJsonPath('error.code', 'ADMIN_USER_EXISTS');
    }

    public function test_missing_email_returns_400(): void
    {
        $response = $this->postJson('/v1/admin/users', [
            'display_name' => 'No Email',
            'password'     => 'Secure#Pass1',
        ], $this->adminHeaders());

        $response->assertStatus(400);
    }

    public function test_missing_password_returns_400(): void
    {
        $response = $this->postJson('/v1/admin/users', [
            'email'        => 'nopw@olekard.local',
            'display_name' => 'No Password',
        ], $this->adminHeaders());

        $response->assertStatus(400);
    }

    public function test_weak_password_returns_400(): void
    {
        $response = $this->postJson('/v1/admin/users', [
            'email'        => 'weakpw@olekard.local',
            'display_name' => 'Weak Password',
            'password'     => 'short',
        ], $this->adminHeaders());

        $response->assertStatus(400);
    }

    public function test_created_admin_has_admin_role_in_db(): void
    {
        $response = $this->postJson('/v1/admin/users', [
            'email'        => 'withrole@olekard.local',
            'display_name' => 'With Role',
            'password'     => 'Secure#Pass1',
        ], $this->adminHeaders());

        $response->assertStatus(201);
        $userId = $response->json('data.id');

        $user = \DB::connection('identity')
            ->table('identity.users')
            ->where('id', $userId)
            ->first(['default_role_id']);

        $this->assertNotNull($user->default_role_id);

        $role = \DB::connection('identity')
            ->table('identity.roles')
            ->where('id', $user->default_role_id)
            ->first(['slug']);

        $this->assertSame('admin', $role->slug);
    }

    public function test_customer_actor_cannot_create_admin(): void
    {
        $response = $this->postJson('/v1/admin/users', [
            'email'        => 'blocked@olekard.local',
            'display_name' => 'Blocked',
            'password'     => 'Secure#Pass1',
        ], ['X-Test-User-Id' => $this->adminId, 'X-Test-Actor-Type' => 'CUSTOMER']);

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Idempotency tests (M3 — T045/EC-10)
    // -------------------------------------------------------------------------

    private function idempotentAdminHeaders(string $key): array
    {
        return array_merge($this->adminHeaders(), [
            'Idempotency-Key' => $key,
            'X-Actor-ID'      => $this->adminId,
        ]);
    }

    public function test_idempotency_key_replay_returns_same_resource(): void
    {
        $key     = (string) \Ramsey\Uuid\Uuid::uuid7();
        $payload = [
            'email'        => 'idem-replay@olekard.local',
            'display_name' => 'Idempotency Replay',
            'password'     => 'Secure#Pass1',
        ];

        $first  = $this->postJson('/v1/admin/users', $payload, $this->idempotentAdminHeaders($key));
        $second = $this->postJson('/v1/admin/users', $payload, $this->idempotentAdminHeaders($key));

        $first->assertStatus(201);
        $second->assertStatus(200);
        $second->assertHeader('Idempotency-Replay', 'true');
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }

    public function test_idempotency_replay_does_not_create_duplicate_user(): void
    {
        $key     = (string) \Ramsey\Uuid\Uuid::uuid7();
        $payload = [
            'email'        => 'idem-nodup@olekard.local',
            'display_name' => 'No Duplicate',
            'password'     => 'Secure#Pass1',
        ];

        $this->postJson('/v1/admin/users', $payload, $this->idempotentAdminHeaders($key))->assertStatus(201);
        $this->postJson('/v1/admin/users', $payload, $this->idempotentAdminHeaders($key))->assertStatus(200);

        $count = \DB::connection('identity')
            ->table('identity.users')
            ->where('email', 'idem-nodup@olekard.local')
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_without_idempotency_key_behaves_normally(): void
    {
        $response = $this->postJson('/v1/admin/users', [
            'email'        => 'no-idem-key@olekard.local',
            'display_name' => 'No Idempotency Key',
            'password'     => 'Secure#Pass1',
        ], $this->adminHeaders());

        $response->assertStatus(201);
        $response->assertHeaderMissing('Idempotency-Replay');
    }

    public function test_invalid_idempotency_key_format_returns_400(): void
    {
        $headers  = array_merge($this->adminHeaders(), ['Idempotency-Key' => 'not-a-uuid']);
        $response = $this->postJson('/v1/admin/users', [
            'email'        => 'invalid-idem@olekard.local',
            'display_name' => 'Invalid Idempotency Key',
            'password'     => 'Secure#Pass1',
        ], $headers);

        $response->assertStatus(400)
                 ->assertJsonPath('error.code', 'INVALID_IDEMPOTENCY_KEY');
    }

    public function test_idempotency_does_not_bypass_authorization(): void
    {
        $headers = [
            'X-Test-User-Id'    => $this->adminId,
            'X-Test-Actor-Type' => 'CUSTOMER',
            'Idempotency-Key'   => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'X-Actor-ID'        => $this->adminId,
        ];

        $response = $this->postJson('/v1/admin/users', [
            'email'        => 'bypass-auth@olekard.local',
            'display_name' => 'Bypass Auth',
            'password'     => 'Secure#Pass1',
        ], $headers);

        $response->assertStatus(403);
    }

    public function test_idempotency_does_not_bypass_duplicate_email_protection(): void
    {
        $payload = [
            'email'        => 'dup-idem-protect@olekard.local',
            'display_name' => 'Dup Idem Protect',
            'password'     => 'Secure#Pass1',
        ];

        // First creation without idempotency key
        $this->postJson('/v1/admin/users', $payload, $this->adminHeaders())->assertStatus(201);

        // Second creation with a NEW idempotency key → duplicate email still returns 409
        $response = $this->postJson(
            '/v1/admin/users',
            $payload,
            $this->idempotentAdminHeaders((string) \Ramsey\Uuid\Uuid::uuid7()),
        );

        $response->assertStatus(409)
                 ->assertJsonPath('error.code', 'ADMIN_USER_EXISTS');
    }
}
