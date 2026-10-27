<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Base test class for Ledger module feature, security, integration, and concurrency tests.
 *
 * Extends IdentityTestCase (DatabaseTransactions on null + identity, FakeRedisConnection).
 *
 * Adds:
 * - FakeIdentityAuthMiddleware override so routes do not require a real JWT
 * - Helper methods for creating actors with DB-backed ledger permissions
 */
abstract class LedgerTestCase extends IdentityTestCase
{
    /** @var string[] */
    protected $connectionsToTransact = [null, 'identity'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
    }

    // ── Actor factory helpers ─────────────────────────────────────────────────

    protected function makeAdminWithPermission(string ...$permissions): object
    {
        return $this->makeActorWithPermissions('ADMIN', ...$permissions);
    }

    protected function makeActorWithPermission(string $actorType, string ...$permissions): object
    {
        return $this->makeActorWithPermissions($actorType, ...$permissions);
    }

    protected function withActorToken(object $user, array $extraHeaders = []): static
    {
        return $this->withHeaders(array_merge([
            'X-Test-User-Id'    => $user->id,
            'X-Test-Actor-Type' => $user->actor_type,
        ], $extraHeaders));
    }

    // ── Private factory ───────────────────────────────────────────────────────

    /**
     * Create an actor in identity.users with a freshly seeded role + permissions.
     *
     * Steps:
     *  1. If permissions requested: create a unique role, insertOrIgnore each
     *     permission slug into identity.permissions, assign via role_permissions.
     *  2. Create a user with default_role_id pointing at that role (or null if none).
     */
    private function makeActorWithPermissions(string $actorType, string ...$permissions): object
    {
        $roleId = null;

        if (!empty($permissions)) {
            $roleId = (string) Uuid::uuid7();

            DB::connection('identity')->table('identity.roles')->insert([
                'id'             => $roleId,
                'slug'           => 'test-role-' . substr($roleId, -12) . '-' . bin2hex(random_bytes(4)),
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
                    'module'               => explode('.', $slug)[0] ?? 'ledger',
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
        $email  = 'ledger-test-' . substr($userId, -12) . '-' . bin2hex(random_bytes(4)) . '@test.local';
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

        return (object) [
            'id'         => $userId,
            'email'      => $email,
            'actor_type' => $actorType,
        ];
    }
}
