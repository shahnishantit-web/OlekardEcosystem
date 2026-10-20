<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Identity;

use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityTestCase;

/**
 * T081 — SC-E04-007: Backfill migration correctness.
 *
 * Verifies that migration 2026_09_09_009000_backfill_user_default_roles:
 *  - Sets the correct default_role_id for CUSTOMER, AGENT, and ADMIN users
 *    whose default_role_id is currently null.
 *  - Does NOT overwrite a user whose default_role_id is already set.
 *  - Leaves unrecognized actor_type users with default_role_id = null (EC-10).
 *  - Is idempotent: running twice does not corrupt already-backfilled users.
 *
 * Strategy: insert test users with null default_role_id within the test
 * transaction, then invoke the migration's up() directly and assert the
 * resulting state.
 */
final class BackfillMigrationTest extends IdentityTestCase
{
    private string $customerRoleId;
    private string $agentRoleId;
    private string $adminRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerRoleId = DB::connection('identity')
            ->table('identity.roles')->where('slug', 'customer')->value('id');

        $this->agentRoleId = DB::connection('identity')
            ->table('identity.roles')->where('slug', 'agent')->value('id');

        $this->adminRoleId = DB::connection('identity')
            ->table('identity.roles')->where('slug', 'admin')->value('id');
    }

    // -------------------------------------------------------------------------
    // Happy path: backfill sets correct role by actor type
    // -------------------------------------------------------------------------

    public function test_customer_user_with_null_role_gets_customer_role_after_backfill(): void
    {
        $userId = (string) \Ramsey\Uuid\Uuid::uuid7();
        $this->insertUser($userId, 'CUSTOMER', null);

        $this->runBackfillMigration();

        $defaultRoleId = DB::connection('identity')
            ->table('identity.users')->where('id', $userId)->value('default_role_id');

        $this->assertSame(
            $this->customerRoleId,
            $defaultRoleId,
            'CUSTOMER user with null default_role_id must receive the customer role after backfill.',
        );
    }

    public function test_agent_user_with_null_role_gets_agent_role_after_backfill(): void
    {
        $userId = (string) \Ramsey\Uuid\Uuid::uuid7();
        $this->insertUser($userId, 'AGENT', null);

        $this->runBackfillMigration();

        $defaultRoleId = DB::connection('identity')
            ->table('identity.users')->where('id', $userId)->value('default_role_id');

        $this->assertSame(
            $this->agentRoleId,
            $defaultRoleId,
            'AGENT user with null default_role_id must receive the agent role after backfill.',
        );
    }

    public function test_admin_user_with_null_role_gets_admin_role_after_backfill(): void
    {
        $userId = (string) \Ramsey\Uuid\Uuid::uuid7();
        $this->insertUser($userId, 'ADMIN', null);

        $this->runBackfillMigration();

        $defaultRoleId = DB::connection('identity')
            ->table('identity.users')->where('id', $userId)->value('default_role_id');

        $this->assertSame(
            $this->adminRoleId,
            $defaultRoleId,
            'ADMIN user with null default_role_id must receive the admin role after backfill.',
        );
    }

    // -------------------------------------------------------------------------
    // Guard: existing default_role_id must NOT be overwritten
    // -------------------------------------------------------------------------

    public function test_backfill_does_not_overwrite_existing_default_role_id(): void
    {
        $userId = (string) \Ramsey\Uuid\Uuid::uuid7();
        // Insert a CUSTOMER user that already has the agent role set (unusual but valid data).
        $this->insertUser($userId, 'CUSTOMER', $this->agentRoleId);

        $this->runBackfillMigration();

        $defaultRoleId = DB::connection('identity')
            ->table('identity.users')->where('id', $userId)->value('default_role_id');

        $this->assertSame(
            $this->agentRoleId,
            $defaultRoleId,
            'Backfill must not overwrite a user whose default_role_id is already set (WHERE NULL guard).',
        );
    }

    // -------------------------------------------------------------------------
    // EC-10: unrecognized actor_type → default_role_id stays null, no error
    // -------------------------------------------------------------------------

    public function test_unrecognized_actor_type_retains_null_default_role_id_after_backfill(): void
    {
        $userId = (string) \Ramsey\Uuid\Uuid::uuid7();
        $this->insertUser($userId, 'ENTERPRISE', null);

        // Must not throw — migration continues without error for unrecognized types.
        $this->runBackfillMigration();

        $defaultRoleId = DB::connection('identity')
            ->table('identity.users')->where('id', $userId)->value('default_role_id');

        $this->assertNull(
            $defaultRoleId,
            'EC-10: user with unrecognized actor_type must retain null default_role_id after backfill.',
        );
    }

    // -------------------------------------------------------------------------
    // Idempotency: running the backfill twice must not corrupt state
    // -------------------------------------------------------------------------

    public function test_backfill_is_idempotent(): void
    {
        $userId = (string) \Ramsey\Uuid\Uuid::uuid7();
        $this->insertUser($userId, 'CUSTOMER', null);

        $this->runBackfillMigration(); // First run: sets default_role_id
        $this->runBackfillMigration(); // Second run: WHERE NULL guard skips already-backfilled users

        $defaultRoleId = DB::connection('identity')
            ->table('identity.users')->where('id', $userId)->value('default_role_id');

        $this->assertSame(
            $this->customerRoleId,
            $defaultRoleId,
            'Backfill must be idempotent: running twice must not corrupt the already-set default_role_id.',
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function insertUser(string $id, string $actorType, ?string $defaultRoleId): void
    {
        static $seq = 0;
        $seq++;

        DB::connection('identity')->table('identity.users')->insert([
            'id'             => $id,
            'email'          => "backfill-test-{$seq}@t.local",
            'mobile_number'  => "+19000{$seq}",
            'status'         => 'active',
            'actor_type'     => $actorType,
            'default_role_id' => $defaultRoleId,
            'created_at'     => now()->toDateTimeString(),
            'updated_at'     => now()->toDateTimeString(),
        ]);
    }

    private function runBackfillMigration(): void
    {
        $migration = require base_path(
            'app/Modules/Identity/Infrastructure/Migrations/2026_09_09_009000_backfill_user_default_roles.php'
        );
        $migration->up();
    }
}
