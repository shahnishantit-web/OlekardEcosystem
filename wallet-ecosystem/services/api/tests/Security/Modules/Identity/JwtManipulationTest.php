<?php

declare(strict_types=1);

namespace Tests\Security\Modules\Identity;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\IdentityTestCase;

/**
 * T078 — Server-side permission authority (FR-E04-018).
 *
 * Proves that the permissions resolved from the database are authoritative,
 * and that a crafted X-Test-Permissions claim (standing in for a manipulated
 * JWT permissions payload) cannot bypass server-side hasPermission() checks.
 *
 * SC-E04-001 gate: NOT this test. Use T088 (static grep) + T084 (TokenService regression).
 * This test independently verifies FR-E04-018 only.
 */
final class JwtManipulationTest extends IdentityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
    }

    public function test_crafted_permission_claim_is_denied_when_user_has_no_db_permissions(): void
    {
        // Actor with ADMIN type but NO roles and NO permissions in the DB
        $actorId = (string) Uuid::uuid7();
        DB::connection('identity')->table('identity.users')->insert([
            'id'             => $actorId,
            'email'          => 'no-perms@t.local',
            'mobile_number'  => null,
            'status'         => 'active',
            'actor_type'     => 'ADMIN',
            'default_role_id' => null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Crafted claim: X-Test-Permissions simulates a manipulated JWT payload
        // claiming admin.role.read. The user has NO such permission in the DB.
        $response = $this->getJson('/v1/admin/roles', [
            'X-Test-User-Id'     => $actorId,
            'X-Test-Actor-Type'  => 'ADMIN',
            'X-Test-Permissions' => 'admin.role.read',
        ]);

        // Server-side hasPermission() queries the DB → 0 permissions → 403
        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'AUTHORIZATION_001');
        $this->assertNotEmpty($response->json('error.trace_id'));
    }

    public function test_wildcard_permission_claim_is_denied_when_user_has_no_db_permissions(): void
    {
        // Wildcard '*' in JWT permissions claim must never bypass server-side check (FR-E04-018, SC-E04-001)
        $actorId = (string) Uuid::uuid7();
        DB::connection('identity')->table('identity.users')->insert([
            'id'             => $actorId,
            'email'          => 'wildcard@t.local',
            'mobile_number'  => null,
            'status'         => 'active',
            'actor_type'     => 'ADMIN',
            'default_role_id' => null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $response = $this->getJson('/v1/admin/roles', [
            'X-Test-User-Id'     => $actorId,
            'X-Test-Actor-Type'  => 'ADMIN',
            'X-Test-Permissions' => '*',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'AUTHORIZATION_001');
    }

    public function test_actor_with_db_permission_is_granted_access(): void
    {
        // Control case: a user whose DB permissions are correct IS granted access,
        // proving the mechanism works and this is not a blanket 403 bug.
        $adminRole = DB::connection('identity')
            ->table('identity.roles')
            ->where('slug', 'admin')
            ->first(['id']);

        $this->assertNotNull($adminRole, 'Seeded admin role must exist');

        $actorId = (string) Uuid::uuid7();
        DB::connection('identity')->table('identity.users')->insert([
            'id'             => $actorId,
            'email'          => 'granted@t.local',
            'mobile_number'  => null,
            'status'         => 'active',
            'actor_type'     => 'ADMIN',
            'default_role_id' => $adminRole->id,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // No X-Test-Permissions header — auth relies entirely on DB role lookup
        $response = $this->getJson('/v1/admin/roles', [
            'X-Test-User-Id'    => $actorId,
            'X-Test-Actor-Type' => 'ADMIN',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }
}
