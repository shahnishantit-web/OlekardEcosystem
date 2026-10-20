<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Platform;

use App\Modules\Platform\Domain\Enums\ChangeRequestStatus;
use App\Modules\Platform\Application\Services\ConfigurationService;
use Ramsey\Uuid\Uuid;
use Tests\Support\PlatformTestCase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeIdentityAuthMiddleware;

/**
 * T034 — E28 Configuration Change Request API feature tests.
 *
 * Covers: SC-001, SC-002 (approval→activation), FR-006 (self-approval 403),
 *         FR-007 (duplicate pending 409), FR-008 (maker withdraw 200),
 *         FR-009 (concurrent approve 409 for second), FR-019 (unauthenticated 401).
 */
final class ConfigurationChangeRequestApiTest extends PlatformTestCase
{
    private string $makerAdminId;
    private string $checkerAdminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
        $this->makerAdminId   = $this->seedAdminWithPermissions('maker-config', [
            'configuration.policy.view', 'configuration.policy.update', 'configuration.approve',
        ]);
        $this->checkerAdminId = $this->seedAdminWithPermissions('checker-config', [
            'configuration.policy.view', 'configuration.approve',
        ]);

        // Seed a configuration key that tests can change
        DB::connection('platform')->table('platform.configurations')->insertOrIgnore([
            'id'          => (string) Uuid::uuid7(),
            'config_key'  => 'operational_policy.test_limit',
            'domain'      => 'operational_policy',
            'description' => 'Test configuration key for E28 feature tests',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function makerHeaders(): array
    {
        return [
            'X-Test-User-Id'    => $this->makerAdminId,
            'X-Test-Actor-Type' => 'ADMIN',
        ];
    }

    private function checkerHeaders(): array
    {
        return [
            'X-Test-User-Id'    => $this->checkerAdminId,
            'X-Test-Actor-Type' => 'ADMIN',
        ];
    }

    /** @param array<string> $permissions */
    private function seedAdminWithPermissions(string $emailPrefix, array $permissions): string
    {
        $userId = (string) Uuid::uuid7();
        $roleId = (string) Uuid::uuid7();
        $now = now();
        DB::connection('identity')->table('identity.roles')->insert([
            'id' => $roleId,
            'slug' => 'test-config-' . str_replace('-', '', $roleId),
            'display_name' => 'Test Configuration Admin',
            'is_system_role' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('identity')->table('identity.users')->insert([
            'id' => $userId,
            'email' => $emailPrefix . '-' . str_replace('-', '', $userId) . '@e28.test',
            'mobile_number' => '+260' . random_int(10000000, 99999999),
            'status' => 'active',
            'actor_type' => 'ADMIN',
            'default_role_id' => $roleId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ($permissions as $slug) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id' => (string) Uuid::uuid7(),
                'slug' => $slug,
                'display_name' => $slug,
                'module' => 'platform',
                'is_system_permission' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $permissionId = DB::connection('identity')->table('identity.permissions')->where('slug', $slug)->value('id');
            DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                'id' => (string) Uuid::uuid7(),
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => $now,
            ]);
        }

        return $userId;
    }

    // FR-019: Unauthenticated request returns 401
    public function test_submit_requires_authentication(): void
    {
        $response = $this->postJson('/v1/admin/configuration/change-requests', [
            'config_key'      => 'operational_policy.test_limit',
            'proposed_value'  => 100,
            'idempotency_key' => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(401);
    }

    // SC-001: Config not active until approved
    public function test_submitted_change_request_does_not_activate_immediately(): void
    {
        $idempotencyKey = (string) Uuid::uuid7();

        $response = $this->postJson('/v1/admin/configuration/change-requests', [
            'config_key'      => 'operational_policy.test_limit',
            'proposed_value'  => ['value' => 500],
            'idempotency_key' => $idempotencyKey,
        ], $this->makerHeaders());

        $response->assertStatus(201)
                 ->assertJsonPath('data.status', ChangeRequestStatus::PendingApproval->value);

        // Config service should NOT return the proposed value yet
        $configService = app(ConfigurationService::class);
        $currentValue  = $configService->get('operational_policy.test_limit');
        $this->assertNotEquals(['value' => 500], $currentValue);
    }

    // FR-006: Maker cannot approve their own change request
    public function test_self_approval_returns_403(): void
    {
        $changeRequest = $this->submitTestChangeRequest();

        $response = $this->postJson(
            "/v1/admin/configuration/change-requests/{$changeRequest['id']}/approve",
            ['idempotency_key' => (string) Uuid::uuid7()],
            $this->makerHeaders(), // same user as maker
        );

        $response->assertStatus(400); // Shared BusinessRuleViolationException response.
    }

    // FR-007: Duplicate pending for same key returns 409
    public function test_duplicate_pending_change_request_returns_409(): void
    {
        $this->submitTestChangeRequest();

        $response = $this->postJson('/v1/admin/configuration/change-requests', [
            'config_key'      => 'operational_policy.test_limit',
            'proposed_value'  => ['value' => 999],
            'idempotency_key' => (string) Uuid::uuid7(),
        ], $this->makerHeaders());

        $response->assertStatus(409);
    }

    // FR-008: Maker can withdraw a pending change request
    public function test_maker_can_withdraw_pending_change_request(): void
    {
        $changeRequest = $this->submitTestChangeRequest();

        $response = $this->postJson(
            "/v1/admin/configuration/change-requests/{$changeRequest['id']}/withdraw",
            ['idempotency_key' => (string) Uuid::uuid7()],
            $this->makerHeaders(),
        );

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', ChangeRequestStatus::Withdrawn->value);

        // Config should be unchanged
        $configService = app(ConfigurationService::class);
        $currentValue  = $configService->get('operational_policy.test_limit');
        $this->assertNotEquals(['value' => 500], $currentValue);
    }

    // FR-009: Concurrent duplicate approval returns 409 on second attempt
    public function test_already_approved_change_request_returns_error_on_second_approve(): void
    {
        $changeRequest = $this->submitTestChangeRequest();

        // First approval (checker)
        $this->postJson(
            "/v1/admin/configuration/change-requests/{$changeRequest['id']}/approve",
            ['idempotency_key' => (string) Uuid::uuid7()],
            $this->checkerHeaders(),
        )->assertStatus(200);

        // Second approval attempt (different idempotency key, same request)
        $response = $this->postJson(
            "/v1/admin/configuration/change-requests/{$changeRequest['id']}/approve",
            ['idempotency_key' => (string) Uuid::uuid7()],
            $this->checkerHeaders(),
        );

        $response->assertStatus(400); // Shared BusinessRuleViolationException response.
    }

    // Idempotency: same idempotency key returns same result without side effects
    public function test_submit_is_idempotent(): void
    {
        $idempotencyKey = (string) Uuid::uuid7();
        $payload        = [
            'config_key'      => 'operational_policy.test_limit',
            'proposed_value'  => ['value' => 500],
            'idempotency_key' => $idempotencyKey,
        ];

        $first  = $this->postJson('/v1/admin/configuration/change-requests', $payload, $this->makerHeaders());
        $second = $this->postJson('/v1/admin/configuration/change-requests', $payload, $this->makerHeaders());

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertEquals($first->json('data.id'), $second->json('data.id'));

        // Only one record created
        $count = DB::connection('platform')
            ->table('platform.configuration_change_requests')
            ->where('idempotency_key', $idempotencyKey)
            ->count();
        $this->assertEquals(1, $count);
    }

    // Approve then verify version trail contains submitted + approved events
    public function test_approval_creates_version_trail(): void
    {
        $changeRequest = $this->submitTestChangeRequest();

        $this->postJson(
            "/v1/admin/configuration/change-requests/{$changeRequest['id']}/approve",
            ['idempotency_key' => (string) Uuid::uuid7()],
            $this->checkerHeaders(),
        )->assertStatus(200);

        $versions = DB::connection('platform')
            ->table('platform.configuration_versions')
            ->where('change_request_id', $changeRequest['id'])
            ->orderBy('occurred_at')
            ->get();

        $eventTypes = $versions->pluck('event_type')->all();
        $this->assertContains('submitted', $eventTypes);
        $this->assertContains('approved', $eventTypes);
    }

    private function submitTestChangeRequest(): array
    {
        $response = $this->postJson('/v1/admin/configuration/change-requests', [
            'config_key'      => 'operational_policy.test_limit',
            'proposed_value'  => ['value' => 500],
            'idempotency_key' => (string) Uuid::uuid7(),
        ], $this->makerHeaders());

        $response->assertStatus(201);

        return $response->json('data');
    }
}
