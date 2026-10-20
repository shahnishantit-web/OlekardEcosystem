<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Identity;

use App\Modules\Identity\Application\Services\AdminRoleService;
use Ramsey\Uuid\Uuid;
use Tests\Support\AssertAuditEvent;
use Tests\Support\IdentityTestCase;

/**
 * T042 — AdminRoleService: service-level unit tests for role creation, slug uniqueness, and audit.
 *
 * Governs: FR-E04-029 (role creation), BR-E04-003 (slug uniqueness including soft-deleted),
 *          BR-E04-016 (system role deletion guard), FR-E04-047 (role creation audit).
 */
final class AdminRoleServiceTest extends IdentityTestCase
{
    use AssertAuditEvent;

    private AdminRoleService $service;
    private string $actorId;
    private string $correlationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service       = $this->app->make(AdminRoleService::class);
        $this->actorId       = (string) Uuid::uuid7();
        $this->correlationId = (string) Uuid::uuid7();
    }

    public function test_create_role_returns_role_with_correct_slug(): void
    {
        $role = $this->service->createRole([
            'slug'         => 'unit-test-role',
            'display_name' => 'Unit Test Role',
        ], $this->actorId, $this->correlationId);

        $this->assertSame('unit-test-role', $role->slug);
        $this->assertFalse((bool) $role->is_system_role);
        $this->assertNull($role->deleted_at);
    }

    public function test_create_role_persists_to_database(): void
    {
        $this->service->createRole([
            'slug'         => 'persisted-role',
            'display_name' => 'Persisted Role',
        ], $this->actorId, $this->correlationId);

        $count = \DB::connection('identity')
            ->table('identity.roles')
            ->where('slug', 'persisted-role')
            ->whereNull('deleted_at')
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_create_role_throws_slug_conflict_for_active_role(): void
    {
        \DB::connection('identity')->table('identity.roles')->insert([
            'id'             => (string) Uuid::uuid7(),
            'slug'           => 'active-conflict',
            'display_name'   => 'Active Conflict',
            'is_system_role' => false,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ROLE_SLUG_CONFLICT');

        $this->service->createRole([
            'slug'         => 'active-conflict',
            'display_name' => 'Duplicate Role',
        ], $this->actorId, $this->correlationId);
    }

    public function test_create_role_throws_slug_conflict_for_soft_deleted_role(): void
    {
        // Slug uniqueness must span active AND soft-deleted records (BR-E04-003, EC-09)
        \DB::connection('identity')->table('identity.roles')->insert([
            'id'             => (string) Uuid::uuid7(),
            'slug'           => 'deleted-conflict',
            'display_name'   => 'Soft Deleted Role',
            'is_system_role' => false,
            'deleted_at'     => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ROLE_SLUG_CONFLICT');

        $this->service->createRole([
            'slug'         => 'deleted-conflict',
            'display_name' => 'Role Reusing Deleted Slug',
        ], $this->actorId, $this->correlationId);
    }

    public function test_create_role_writes_audit_event(): void
    {
        $this->service->createRole([
            'slug'         => 'audited-role',
            'display_name' => 'Audited Role',
        ], $this->actorId, $this->correlationId);

        $this->assertAuditEventRecorded('identity.role.created', [
            'actor_id' => $this->actorId,
            'outcome'  => 'success',
        ]);
    }

    public function test_soft_delete_throws_for_system_role(): void
    {
        $adminRole = \DB::connection('identity')
            ->table('identity.roles')
            ->where('slug', 'admin')
            ->first(['id']);

        $this->assertNotNull($adminRole, 'Seeded admin role must exist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('SYSTEM_ROLE_DELETION_DENIED');

        $this->service->softDelete((string) $adminRole->id, $this->actorId, $this->correlationId);
    }

    public function test_soft_delete_succeeds_for_non_system_role(): void
    {
        $roleId = (string) Uuid::uuid7();
        \DB::connection('identity')->table('identity.roles')->insert([
            'id'             => $roleId,
            'slug'           => 'deletable-role',
            'display_name'   => 'Deletable Role',
            'is_system_role' => false,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->service->softDelete($roleId, $this->actorId, $this->correlationId);

        $deletedAt = \DB::connection('identity')
            ->table('identity.roles')
            ->where('id', $roleId)
            ->value('deleted_at');

        $this->assertNotNull($deletedAt);
    }
}
