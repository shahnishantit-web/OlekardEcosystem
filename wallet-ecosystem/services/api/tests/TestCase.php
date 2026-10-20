<?php

namespace Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;

abstract class TestCase extends BaseTestCase
{
    /**
     * Wrap all module schema connections in the per-test DB transaction so that
     * writes made during a test are rolled back automatically after it completes.
     * All connections share the same wallet_ecosystem Postgres instance.
     */
    protected $connectionsToTransact = [
        'platform',
        'identity',
        'profile',
        'wallet',
        'ledger',
        'kyc',
        'transaction',
        'risk',
        'agent',
        'notification',
        'funding',
        'transfer',
    ];

    protected function setUp(): void
    {
        // Force APP_ENV=testing before the app boots so service-provider guards
        // (e.g. FundingServiceProvider::validateFloatAccountConfig) are skipped.
        // The Docker container environment has APP_ENV=local which phpunit.xml
        // cannot override reliably in all PHP/PHPUnit versions.
        $_ENV['APP_ENV']    = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
        putenv('APP_ENV=testing');

        // Ensure AUDIT_HMAC_KEY is set so AuditServiceProvider::boot() does not throw
        // when running outside Docker (where the real .env may not have this key).
        if (empty($_ENV['AUDIT_HMAC_KEY'])) {
            $_ENV['AUDIT_HMAC_KEY']    = 'test-hmac-key-for-phpunit-only';
            $_SERVER['AUDIT_HMAC_KEY'] = 'test-hmac-key-for-phpunit-only';
            putenv('AUDIT_HMAC_KEY=test-hmac-key-for-phpunit-only');
        }

        parent::setUp();
        // Redirect the 'redis' cache store to in-memory array driver when
        // phpredis extension or predis library is unavailable in the test env.
        config(['cache.stores.redis' => ['driver' => 'array']]);
    }

    // ── Auth helpers ──────────────────────────────────────────────────────────

    /**
     * Authenticate as a user with the given ID. Creates a DB record if permissions are
     * specified; otherwise just sets the auth headers (use when the record is pre-seeded).
     *
     * @param  array<string> $permissions
     */
    protected function actingAsUser(string $userId, string $actorType = 'CUSTOMER', array $permissions = []): static
    {
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

        if (!empty($permissions)) {
            $roleId = (string) \Ramsey\Uuid\Uuid::uuid7();

            \Illuminate\Support\Facades\DB::connection('identity')->table('identity.roles')->insert([
                'id'             => $roleId,
                'slug'           => 'test-role-' . $roleId,
                'display_name'   => 'Test Role',
                'is_system_role' => false,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            foreach ($permissions as $slug) {
                \Illuminate\Support\Facades\DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                    'id'                   => (string) \Ramsey\Uuid\Uuid::uuid7(),
                    'slug'                 => $slug,
                    'display_name'         => $slug,
                    'module'               => explode('.', $slug)[0] ?? 'notification',
                    'is_system_permission' => false,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);

                $perm = \Illuminate\Support\Facades\DB::connection('identity')
                    ->table('identity.permissions')
                    ->where('slug', $slug)
                    ->first(['id']);

                \Illuminate\Support\Facades\DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                    'id'            => (string) \Ramsey\Uuid\Uuid::uuid7(),
                    'role_id'       => $roleId,
                    'permission_id' => $perm->id,
                    'created_at'    => now(),
                ]);
            }

            \Illuminate\Support\Facades\DB::connection('identity')->table('identity.users')->insertOrIgnore([
                'id'              => $userId,
                'email'           => $actorType . '-' . $userId . '@test.local',
                'mobile_number'   => '+9' . mt_rand(100000000, 999999999),
                'status'          => 'active',
                'actor_type'      => $actorType,
                'default_role_id' => $roleId,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        $this->withHeaders([
            'X-Test-User-Id'    => $userId,
            'X-Test-Actor-Type' => $actorType,
        ]);
        return $this;
    }

    /**
     * Create a DB-backed ADMIN user with the given permission slugs, register
     * FakeIdentityAuthMiddleware, and return a GenericUser suitable for actingAs().
     *
     * @param  array<string> $permissions
     */
    protected function createAuthenticatedAdmin(array $permissions = []): GenericUser
    {
        return $this->createAuthenticatedActor('ADMIN', $permissions);
    }

    /**
     * Convenience fluent helper: creates an ADMIN user with a single permission and
     * immediately calls actingAs() so tests can chain HTTP call methods directly.
     *
     * Usage: $this->actingAsAdminWithPermission('qa.execute')->getJson('/v1/qa/dashboard')
     */
    protected function actingAsAdminWithPermission(string $permission): static
    {
        $user = $this->createAuthenticatedAdmin([$permission]);
        return $this->actingAs($user);
    }

    /**
     * Create a DB-backed CUSTOMER user with the given permission slugs, register
     * FakeIdentityAuthMiddleware, and return a GenericUser suitable for actingAs().
     *
     * @param  array<string> $permissions
     */
    protected function createAuthenticatedUser(array $permissions = []): GenericUser
    {
        return $this->createAuthenticatedActor('CUSTOMER', $permissions);
    }

    /**
     * Internal helper: seeds identity schema records and wires the fake middleware.
     *
     * @param  array<string> $permissions
     */
    private function createAuthenticatedActor(string $actorType, array $permissions): GenericUser
    {
        // Register the fake JWT middleware so routes don't need a real token.
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

        $roleId = null;

        if (!empty($permissions)) {
            $roleId = (string) Uuid::uuid7();

            DB::connection('identity')->table('identity.roles')->insert([
                'id'             => $roleId,
                'slug'           => 'test-role-' . $roleId,
                'display_name'   => 'Test Role',
                'is_system_role' => false,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            foreach ($permissions as $slug) {
                DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                    'id'                   => (string) Uuid::uuid7(),
                    'slug'                 => $slug,
                    'display_name'         => $slug,
                    'module'               => explode('.', $slug)[0] ?? 'commission',
                    'is_system_permission' => false,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);

                $perm = DB::connection('identity')
                    ->table('identity.permissions')
                    ->where('slug', $slug)
                    ->first(['id']);

                DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                    'id'            => (string) Uuid::uuid7(),
                    'role_id'       => $roleId,
                    'permission_id' => $perm->id,
                    'created_at'    => now(),
                ]);
            }
        }

        $userId = (string) Uuid::uuid7();
        $email  = strtolower($actorType) . '-' . $userId . '@test.local';
        $mobile = '+9' . mt_rand(100000000, 999999999);

        DB::connection('identity')->table('identity.users')->insert([
            'id'              => $userId,
            'email'           => $email,
            'mobile_number'   => $mobile,
            'status'          => 'active',
            'actor_type'      => $actorType,
            'default_role_id' => $roleId,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // Set headers so FakeIdentityAuthMiddleware populates the identity context.
        $this->withHeaders([
            'X-Test-User-Id'    => $userId,
            'X-Test-Actor-Type' => $actorType,
        ]);

        return new GenericUser(['id' => $userId, 'email' => $email]);
    }
}
