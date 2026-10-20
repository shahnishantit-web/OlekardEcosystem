<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Integration;

use App\Modules\Integration\Domain\Enums\ApiClientStatus;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class ApiClientTest extends IntegrationTestCase
{
    // ── POST /v1/integrations/clients ─────────────────────────────────────────

    public function test_admin_can_create_api_client(): void
    {
        $admin = $this->makeAdminWithPermission('integrations.manage');

        $response = $this->withActorToken($admin)
            ->postJson('/v1/integrations/clients', [
                'name' => 'Test Client',
                'permissions' => ['webhooks.receive'],
                'rate_limit_per_minute' => 120,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'name', 'api_key', 'raw_secret', 'status', 'permissions', 'rate_limit_per_minute']]);

        $this->assertSame('Test Client', $response->json('data.name'));
        $this->assertSame('active', $response->json('data.status'));
        $this->assertNotNull($response->json('data.raw_secret'), 'raw_secret must be shown on creation');
    }

    public function test_create_api_client_validates_required_name(): void
    {
        $admin = $this->makeAdminWithPermission('integrations.manage');

        $response = $this->withActorToken($admin)
            ->postJson('/v1/integrations/clients', []);

        $response->assertStatus(422)
            ->assertJsonFragment(['field' => 'name']);
    }

    public function test_create_api_client_requires_permission(): void
    {
        $admin = $this->makeAdminWithPermission('some.other.permission');

        $response = $this->withActorToken($admin)
            ->postJson('/v1/integrations/clients', [
                'name' => 'Test',
            ]);

        $response->assertStatus(403);
    }

    public function test_create_persists_bcrypt_hash_not_raw_secret(): void
    {
        $admin = $this->makeAdminWithPermission('integrations.manage');

        $response = $this->withActorToken($admin)
            ->postJson('/v1/integrations/clients', ['name' => 'Secure Client']);

        $response->assertStatus(201);

        $clientId = $response->json('data.id');
        $rawSecret = $response->json('data.raw_secret');

        $row = DB::connection('platform')->table('integration.api_clients')->where('id', $clientId)->first();

        $this->assertNotNull($row);
        $this->assertNotEquals($rawSecret, $row->secret_hash, 'Raw secret must not be stored in DB');
        $this->assertTrue(password_verify($rawSecret, $row->secret_hash), 'Stored hash must verify against raw secret');
    }

    // ── GET /v1/integrations/clients ─────────────────────────────────────────

    public function test_admin_can_list_api_clients(): void
    {
        $admin = $this->makeAdminWithPermission('integrations.manage');
        $this->ensureApiClient(['name' => 'Client A']);
        $this->ensureApiClient(['name' => 'Client B']);

        $response = $this->withActorToken($admin)
            ->getJson('/v1/integrations/clients');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'name', 'api_key', 'status']]]);

        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
    }

    // ── GET /v1/integrations/clients/{id} ─────────────────────────────────────

    public function test_admin_can_get_api_client(): void
    {
        $admin = $this->makeAdminWithPermission('integrations.manage');
        $client = $this->ensureApiClient(['name' => 'My Client']);

        $response = $this->withActorToken($admin)
            ->getJson("/v1/integrations/clients/{$client['id']}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $client['id'])
            ->assertJsonPath('data.name', 'My Client');
    }

    public function test_get_api_client_returns_404_for_unknown_id(): void
    {
        $admin = $this->makeAdminWithPermission('integrations.manage');

        $response = $this->withActorToken($admin)
            ->getJson('/v1/integrations/clients/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404);
    }

    // ── POST /v1/integrations/clients/{id}/rotate-secret ─────────────────────

    public function test_admin_can_rotate_secret(): void
    {
        $admin = $this->makeAdminWithPermission('integrations.manage');
        $client = $this->ensureApiClient();

        $oldHash = DB::connection('platform')
            ->table('integration.api_clients')
            ->where('id', $client['id'])
            ->value('secret_hash');

        $response = $this->withActorToken($admin)
            ->postJson("/v1/integrations/clients/{$client['id']}/rotate-secret");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['raw_secret']]);

        $newRawSecret = $response->json('data.raw_secret');
        $this->assertNotNull($newRawSecret);
        $this->assertFalse(password_verify($newRawSecret, $oldHash), 'New secret must not verify against old hash');

        $newHash = DB::connection('platform')
            ->table('integration.api_clients')
            ->where('id', $client['id'])
            ->value('secret_hash');

        $this->assertTrue(password_verify($newRawSecret, $newHash), 'New secret must verify against new hash');

        $rotationCount = DB::connection('platform')
            ->table('integration.api_client_key_rotations')
            ->where('api_client_id', $client['id'])
            ->count();

        $this->assertEquals(1, $rotationCount, 'Rotation record must be appended');
    }

    // ── PATCH /v1/integrations/clients/{id}/status ────────────────────────────

    public function test_admin_can_deactivate_api_client(): void
    {
        $admin = $this->makeAdminWithPermission('integrations.manage');
        $client = $this->ensureApiClient();

        $response = $this->withActorToken($admin)
            ->patchJson("/v1/integrations/clients/{$client['id']}/status", [
                'status' => ApiClientStatus::Inactive->value,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_create_does_not_log_credentials_in_plaintext(): void
    {
        $admin = $this->makeAdminWithPermission('integrations.manage');

        $logMessages = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logMessages): void {
            $logMessages[] = $e->message.' '.json_encode($e->context);
        });

        $response = $this->withActorToken($admin)
            ->postJson('/v1/integrations/clients', [
                'name' => 'Credential Safety Check',
                'permissions' => [],
            ]);

        $response->assertStatus(201);
        $rawSecret = $response->json('data.raw_secret');

        foreach ($logMessages as $logLine) {
            $this->assertStringNotContainsString(
                $rawSecret,
                $logLine,
                'Raw secret must never appear in log output (SC-008)',
            );
        }
    }

    public function test_update_status_validates_enum_value(): void
    {
        $admin = $this->makeAdminWithPermission('integrations.manage');
        $client = $this->ensureApiClient();

        $response = $this->withActorToken($admin)
            ->patchJson("/v1/integrations/clients/{$client['id']}/status", [
                'status' => 'suspended',
            ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['field' => 'status']);
    }
}
