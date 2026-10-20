<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security\Audit;

use App\Modules\Identity\Domain\Services\ConfigurationAdminService;
use App\Modules\Identity\Application\Services\AdminUserService;
use App\Modules\Security\Domain\ValueObjects\SecurityAuditActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T047/T048/T049 — Security audit event emission tests.
 * SC-007: 100% of new security event types have test coverage.
 */
final class SecurityAuditEventTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // T047 — Lockout config change emits audit event
    // -------------------------------------------------------------------------

    /** @test */
    public function updating_lockout_config_emits_config_lockout_changed_audit_event(): void
    {
        $this->seedLockoutConfigKey();

        /** @var ConfigurationAdminService $service */
        $service   = $this->app->make(ConfigurationAdminService::class);
        $actorId   = 'admin-' . \Ramsey\Uuid\Uuid::uuid7()->toString();
        $currentRow = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'auth.lockout.max_failed_attempts')
            ->where('status', 'active')
            ->first(['updated_at']);

        $version = $currentRow ? \Carbon\Carbon::parse($currentRow->updated_at)->toIso8601String() : now()->toIso8601String();

        $service->update('auth.lockout.max_failed_attempts', '6', $version, $actorId);

        $event = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', SecurityAuditActions::CONFIG_LOCKOUT_CHANGED)
            ->where('resource_id', 'auth.lockout.max_failed_attempts')
            ->orderByDesc('occurred_at')
            ->first();

        $this->assertNotNull($event, 'security.config.lockout_changed audit event must be written');
        $this->assertSame($actorId, $event->actor_id ?? null);
        $this->assertSame('identity.configuration', $event->resource_type ?? null);
        $this->assertSame('success', $event->outcome ?? null);
    }

    // -------------------------------------------------------------------------
    // T048 — PII access emits audit event
    // -------------------------------------------------------------------------

    /** @test */
    public function reading_admin_user_pii_emits_pii_accessed_audit_event(): void
    {
        $targetId = $this->createAdminUser();
        $actorId  = 'admin-actor-' . \Ramsey\Uuid\Uuid::uuid7()->toString();

        /** @var AdminUserService $service */
        $service = $this->app->make(AdminUserService::class);
        $result  = $service->getAdmin($targetId, $actorId, 'corr-test', '127.0.0.1');

        $this->assertNotNull($result, 'getAdmin should return the user');

        $event = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', SecurityAuditActions::PII_ACCESSED)
            ->where('resource_id', $targetId)
            ->where('actor_id', $actorId)
            ->first();

        $this->assertNotNull($event, 'security.pii.accessed audit event must be written');
        $this->assertSame('identity.user', $event->resource_type ?? null);
        $this->assertSame('success', $event->outcome ?? null);
    }

    /** @test */
    public function reading_admin_user_without_actor_id_does_not_emit_pii_audit_event(): void
    {
        $targetId = $this->createAdminUser();

        /** @var AdminUserService $service */
        $service = $this->app->make(AdminUserService::class);
        // Call without actor_id — should not emit event (anonymous/system lookup)
        $service->getAdmin($targetId);

        $count = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', SecurityAuditActions::PII_ACCESSED)
            ->where('resource_id', $targetId)
            ->count();

        $this->assertSame(0, (int) $count, 'PII_ACCESSED must not be emitted for system lookups without actor_id');
    }

    // -------------------------------------------------------------------------
    // T049 — Audit events are immutable (no DELETE/PUT routes exist)
    // -------------------------------------------------------------------------

    /** @test */
    public function audit_event_delete_route_does_not_exist(): void
    {
        $response = $this->deleteJson('/api/v1/audit-events/00000000-0000-0000-0000-000000000001');

        // 404 or 405 — but never 200/204
        $this->assertContains($response->getStatusCode(), [404, 405], 'No DELETE route for audit events must exist');
    }

    /** @test */
    public function audit_event_update_route_does_not_exist(): void
    {
        $response = $this->patchJson('/api/v1/audit-events/00000000-0000-0000-0000-000000000001', []);

        $this->assertContains($response->getStatusCode(), [404, 405], 'No PATCH route for audit events must exist');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function seedLockoutConfigKey(): void
    {
        DB::connection('platform')->table('platform.configuration_values')->upsert(
            [
                'id'           => (string) \Ramsey\Uuid\Uuid::uuid7(),
                'config_key'   => 'auth.lockout.max_failed_attempts',
                'config_value' => '5',
                'value_type'   => 'integer',
                'status'       => 'active',
                'version'      => 1,
                'is_tbd'       => false,
                'created_at'   => now(),
                'updated_at'   => now(),
            ],
            ['config_key', 'version'],
            ['config_value', 'updated_at'],
        );
    }

    private function createAdminUser(): string
    {
        $id = (string) \Ramsey\Uuid\Uuid::uuid7();
        DB::connection('identity')->table('identity.users')->insert([
            'id'         => $id,
            'email'      => 'test-admin-' . $id . '@example.com',
            'actor_type' => 'ADMIN',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }
}
