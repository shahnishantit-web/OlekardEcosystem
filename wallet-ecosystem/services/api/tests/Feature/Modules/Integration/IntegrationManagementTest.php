<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Integration;

use App\Modules\Integration\Domain\Contracts\IntegrationRegistryInterface;
use App\Modules\Integration\Domain\Enums\IntegrationProviderType;
use App\Modules\Integration\Domain\Enums\IntegrationStatus;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class IntegrationManagementTest extends IntegrationTestCase
{
    // ── POST /v1/integrations/providers ──────────────────────────────────────

    public function test_admin_can_register_integration(): void
    {
        $admin = $this->makeAdminWithPermission('integrations.manage');

        $response = $this->withActorToken($admin)->postJson('/v1/integrations/providers', [
            'provider_type' => IntegrationProviderType::Sms->value,
            'provider_slug' => 'mock-sms-'.uniqid(),
            'display_name' => 'Mock SMS Provider',
            'credentials_reference' => 'platform.integrations.mock_sms',
            'is_default' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'provider_type', 'provider_slug', 'status', 'is_default']]);

        $this->assertSame('active', $response->json('data.status'));
        $this->assertTrue($response->json('data.is_default'));
    }

    public function test_register_duplicate_slug_type_returns_409(): void
    {
        $admin = $this->makeAdminWithPermission('integrations.manage');
        $slug = 'dup-sms-'.uniqid();

        $this->ensureIntegration([
            'provider_type' => IntegrationProviderType::Sms->value,
            'provider_slug' => $slug,
        ]);

        $response = $this->withActorToken($admin)->postJson('/v1/integrations/providers', [
            'provider_type' => IntegrationProviderType::Sms->value,
            'provider_slug' => $slug,
            'display_name' => 'Duplicate',
            'credentials_reference' => 'ref',
        ]);

        $response->assertStatus(409);
    }

    // ── POST /v1/integrations/providers/{id}/set-default ─────────────────────

    public function test_set_default_demotes_previous_default(): void
    {
        $admin = $this->makeAdminWithPermission('integrations.manage');

        // Two SMS integrations — first is default
        $firstId = $this->ensureIntegration([
            'provider_type' => IntegrationProviderType::Sms->value,
            'provider_slug' => 'sms-first-'.uniqid(),
            'is_default' => true,
        ]);

        $secondId = $this->ensureIntegration([
            'provider_type' => IntegrationProviderType::Sms->value,
            'provider_slug' => 'sms-second-'.uniqid(),
            'is_default' => false,
        ]);

        $response = $this->withActorToken($admin)
            ->postJson("/v1/integrations/providers/{$secondId}/set-default");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_default', true);

        // First is now demoted
        $firstRow = DB::connection('platform')
            ->table('integration.integrations')
            ->where('id', $firstId)
            ->first();

        $this->assertFalse((bool) $firstRow->is_default, 'Previous default must be demoted');

        // Registry cache must be invalidated — resolveIntegration must return the new default (SC-007).
        $resolved = $this->app->make(IntegrationRegistryInterface::class)
            ->resolveIntegration(IntegrationProviderType::Sms);

        $this->assertSame($secondId, $resolved?->id, 'Registry must resolve new default after set-default cache invalidation');
    }

    // ── PATCH /v1/integrations/providers/{id}/status ──────────────────────────

    public function test_status_update_is_audit_logged(): void
    {
        $admin = $this->makeAdminWithPermission('integrations.manage');
        $integrationId = $this->ensureIntegration();

        $response = $this->withActorToken($admin)
            ->patchJson("/v1/integrations/providers/{$integrationId}/status", [
                'status' => IntegrationStatus::Inactive->value,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'inactive');

        // Audit record written
        $auditRow = DB::connection('platform')->table('platform.audit_events')
            ->where('action', 'integration.provider.status_changed')
            ->where('resource_id', $integrationId)
            ->first();

        $this->assertNotNull($auditRow, 'Audit event must be recorded for status change');
    }

    // ── GET /v1/integrations/providers ────────────────────────────────────────

    public function test_list_filtered_by_provider_type(): void
    {
        $admin = $this->makeAdminWithPermission('integrations.manage');

        $this->ensureIntegration([
            'provider_type' => IntegrationProviderType::Sms->value,
            'provider_slug' => 'sms-list-'.uniqid(),
        ]);

        $this->ensureIntegration([
            'provider_type' => IntegrationProviderType::Email->value,
            'provider_slug' => 'email-list-'.uniqid(),
        ]);

        $response = $this->withActorToken($admin)
            ->getJson('/v1/integrations/providers?provider_type='.IntegrationProviderType::Sms->value);

        $response->assertStatus(200);

        $types = array_column($response->json('data'), 'provider_type');
        foreach ($types as $type) {
            $this->assertSame(IntegrationProviderType::Sms->value, $type);
        }
    }

    // ── Provider call log ─────────────────────────────────────────────────────

    public function test_provider_call_log_is_written(): void
    {
        $admin = $this->makeAdminWithPermission('integrations.manage');
        $integrationId = $this->ensureIntegration();

        $correlationId = Uuid::uuid7()->toString();

        DB::connection('platform')->table('integration.provider_call_logs')->insert([
            'id' => (string) Uuid::uuid7(),
            'integration_id' => $integrationId,
            'correlation_id' => $correlationId,
            'outcome' => 'success',
            'retry_eligible' => false,
            'created_at' => now(),
        ]);

        $response = $this->withActorToken($admin)
            ->getJson('/v1/integrations/providers/call-logs?correlation_id='.$correlationId);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($correlationId, $response->json('data.0.correlation_id'));
    }
}
