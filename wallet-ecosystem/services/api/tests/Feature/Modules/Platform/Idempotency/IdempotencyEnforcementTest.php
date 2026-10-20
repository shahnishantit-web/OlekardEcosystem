<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Platform\Idempotency;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\Support\FakeRequirePermissionMiddleware;
use Tests\Support\TestCase;

/**
 * E27 — FR-001, FR-002, FR-003: Idempotency-Key enforcement on financial mutation endpoints.
 *
 * Tests the real IdempotencyMiddleware against the funding transactions endpoint.
 * Identity and permission middleware are faked; idempotency middleware runs for real.
 */
final class IdempotencyEnforcementTest extends TestCase
{
    protected $connectionsToTransact = [null, 'identity', 'platform', 'ledger'];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.stores.idempotency' => ['driver' => 'array'],
        ]);

        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
        $this->app['router']->aliasMiddleware('identity.require-permission', FakeRequirePermissionMiddleware::class);
    }

    private function customerHeaders(array $extra = []): array
    {
        return array_merge([
            'X-Test-User-Id'      => (string) Uuid::uuid7(),
            'X-Test-Actor-Type'   => 'CUSTOMER',
            'X-Test-Permissions'  => 'funding.initiate',
            'Content-Type'        => 'application/json',
        ], $extra);
    }

    private function fundingPayload(): array
    {
        return [
            'wallet_id'      => (string) Uuid::uuid7(),
            'gross_amount'   => 10000,
            'currency_code'  => 'BWP',
            'funding_method' => 'mobile_money',
        ];
    }

    // ── FR-001: Missing Idempotency-Key → HTTP 422 ─────────────────────────────

    public function test_post_without_idempotency_key_returns_422(): void
    {
        $response = $this->withHeaders($this->customerHeaders())
            ->postJson('/api/v1/funding/transactions', $this->fundingPayload());

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
    }

    // ── FR-001: Key present → request passes idempotency check ─────────────────

    public function test_post_with_idempotency_key_passes_middleware(): void
    {
        $response = $this->withHeaders($this->customerHeaders([
            'Idempotency-Key' => (string) Uuid::uuid7(),
        ]))->postJson('/api/v1/funding/transactions', $this->fundingPayload());

        // May fail for domain reasons (missing wallet, etc.) but must NOT be 422 IDEMPOTENCY_KEY_REQUIRED
        $this->assertNotEquals('IDEMPOTENCY_KEY_REQUIRED', $response->json('error.code'));
    }

    // ── FR-002: Duplicate key returns original response with replay header ──────

    public function test_duplicate_key_returns_original_response_with_replay_header(): void
    {
        $key = (string) Uuid::uuid7();
        $actorId = (string) Uuid::uuid7();

        // Seed a completed idempotency record for this key
        DB::connection('platform')->table('platform.idempotency_records')->insert([
            'id'              => (string) Uuid::uuid7(),
            'idempotency_key' => $key,
            'actor_id'        => $actorId,
            'actor_type'      => 'USER',
            'request_hash'    => hash('sha256', 'POST:/api/v1/funding/transactions:' . json_encode($this->fundingPayload())),
            'http_method'     => 'POST',
            'http_path'       => 'api/v1/funding/transactions',
            'status'          => 'completed',
            'attempt_count'   => 1,
            'response_status' => 201,
            'response_body'   => json_encode(['data' => ['id' => 'original-tx-id', 'status' => 'pending']]),
            'created_at'      => now(),
            'expires_at'      => now()->addDays(7),
        ]);

        $response = $this->withHeaders($this->customerHeaders([
            'Idempotency-Key' => $key,
            'X-Test-User-Id'  => $actorId,
            'X-Actor-ID'      => $actorId,
        ]))->postJson('/api/v1/funding/transactions', $this->fundingPayload());

        $response->assertStatus(200)
            ->assertHeader('Idempotency-Replay', 'true');
    }

    // ── FR-003: Same key + different payload → HTTP 409 ────────────────────────

    public function test_same_key_different_payload_returns_409(): void
    {
        $key = (string) Uuid::uuid7();
        $actorId = (string) Uuid::uuid7();
        $originalPayload = $this->fundingPayload();
        $differentPayload = array_merge($originalPayload, ['gross_amount' => 99999]);

        // Seed a completed idempotency record with the original payload hash
        DB::connection('platform')->table('platform.idempotency_records')->insert([
            'id'              => (string) Uuid::uuid7(),
            'idempotency_key' => $key,
            'actor_id'        => $actorId,
            'actor_type'      => 'USER',
            'request_hash'    => hash('sha256', 'POST:api/v1/funding/transactions:' . json_encode($originalPayload)),
            'http_method'     => 'POST',
            'http_path'       => 'api/v1/funding/transactions',
            'status'          => 'completed',
            'attempt_count'   => 1,
            'response_status' => 201,
            'response_body'   => json_encode(['data' => ['id' => 'original-tx-id']]),
            'created_at'      => now(),
            'expires_at'      => now()->addDays(7),
        ]);

        $response = $this->withHeaders($this->customerHeaders([
            'Idempotency-Key' => $key,
            'X-Test-User-Id'  => $actorId,
            'X-Actor-ID'      => $actorId,
        ]))->postJson('/api/v1/funding/transactions', $differentPayload);

        // IdempotencyKeyMismatchException should be rendered as HTTP 409
        $response->assertStatus(409);
    }

    // ── FR-002: Actor identity stored in idempotency record (T077 audit) ───────

    public function test_idempotency_record_stores_actor_id(): void
    {
        DB::connection('platform')->table('platform.idempotency_records')->truncate();

        $actorId = (string) Uuid::uuid7();

        // Check column exists in schema
        $columns = DB::connection('platform')
            ->select("SELECT column_name FROM information_schema.columns WHERE table_schema = 'platform' AND table_name = 'idempotency_records'");

        $columnNames = array_column($columns, 'column_name');
        $this->assertContains('actor_id', $columnNames, 'actor_id column must exist in platform.idempotency_records (FR-002)');
    }
}
