<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AdminApp;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * @group docker
 *
 * ADM-P1-013 — Reconciliation & Settlement backend endpoints.
 *
 * SC-001: GET  /v1/admin-app/reconciliation              — list batches
 * SC-002: GET  /v1/admin-app/reconciliation/{id}         — batch detail with unmatched IDs
 * SC-003: GET  /v1/admin-app/settlement                  — list settlements
 * SC-004: GET  /v1/admin-app/settlement/{id}             — settlement detail
 * SC-005: POST /v1/admin-app/settlement/{id}/approve     — approve settlement (maker-checker)
 * SC-006: Authorization guards
 */
final class AdminAppReconciliationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['platform', 'identity', 'reconciliation'];

    protected function setUp(): void
    {
        parent::setUp();
        // Disable Redis permission cache so tests run without a live phpredis extension.
        // parent::setUp() already overrides cache.stores.redis → 'array' driver.
        // We update the DB TTL to 0 (skips Redis in PermissionResolverService) and
        // purge the already-resolved 'redis' cache store so the next access re-resolves
        // using the array-driver config override instead of the real phpredis connector.
        DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'auth.permission_cache_ttl')
            ->update(['config_value' => '0', 'updated_at' => now()]);
        $this->app->make('cache')->forgetDriver('redis');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seedBatch(string $status = 'completed'): string
    {
        $id = (string) Uuid::uuid7();
        DB::connection('reconciliation')->table('reconciliation_batches')->insert([
            'id'               => $id,
            'idempotency_key'  => 'idem-batch-' . $id,
            'source_type'      => 'provider_file',
            'source_reference' => 'REF-' . $id,
            'batch_date'       => '2026-10-01',
            'status'           => $status,
            'total_count'      => 100,
            'matched_count'    => 95,
            'unmatched_count'  => 5,
            'exception_count'  => 2,
            'submitted_by'     => (string) Uuid::uuid7(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
        return $id;
    }

    private function seedItem(string $batchId, string $status, ?string $txId = null): string
    {
        $id = (string) Uuid::uuid7();
        DB::connection('reconciliation')->table('reconciliation_items')->insert([
            'id'                      => $id,
            'batch_id'                => $batchId,
            'item_type'               => 'provider_transaction',
            'provider_reference'      => 'PROV-' . $id,
            'internal_transaction_id' => $txId,
            'amount'                  => 1000.00,
            'currency_code'           => 'BWP',
            'transaction_date'        => '2026-10-01',
            'status'                  => $status,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);
        return $id;
    }

    private function seedSettlement(string $status = 'pending_approval', ?string $makerId = null): string
    {
        $id = (string) Uuid::uuid7();
        DB::connection('reconciliation')->table('settlement_batches')->insert([
            'id'                 => $id,
            'idempotency_key'    => 'idem-settle-' . $id,
            'settlement_type'    => 'provider_settlement',
            'provider_code'      => 'BANCABC',
            'period_start'       => '2026-10-01',
            'period_end'         => '2026-10-08',
            'currency_code'      => 'BWP',
            'gross_amount'       => 10000.00,
            'fee_amount'         => 500.00,
            'net_amount'         => 9500.00,
            'status'             => $status,
            'external_reference' => 'REF-001',
            'maker_id'           => $makerId ?? (string) Uuid::uuid7(),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
        return $id;
    }

    // ── SC-001: List reconciliation batches ──────────────────────────────────

    public function test_sc001_list_batches_returns_paginated_list(): void
    {
        $batchId = $this->seedBatch();
        $this->actingAsAdminWithPermission('adminapp.reconciliation.read');

        $response = $this->getJson('/v1/admin-app/reconciliation');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [['batch_id', 'period_start', 'period_end', 'status', 'total_transactions', 'matched_count', 'unmatched_count', 'discrepancy_amount_minor_units', 'currency_code', 'run_at', 'completed_at']],
            'meta' => ['total', 'per_page', 'current_page', 'last_page'],
        ]);

        $batch = collect($response->json('data'))->firstWhere('batch_id', $batchId);
        $this->assertNotNull($batch);
        $this->assertSame('completed', $batch['status']);
        $this->assertSame(100, $batch['total_transactions']);
    }

    public function test_sc001_list_batches_filters_by_status(): void
    {
        $completedId = $this->seedBatch('completed');
        $this->seedBatch('failed');
        $this->actingAsAdminWithPermission('adminapp.reconciliation.read');

        $response = $this->getJson('/v1/admin-app/reconciliation?status=completed');

        $response->assertStatus(200);
        $batches = collect($response->json('data'));
        $this->assertTrue($batches->every(fn ($b) => $b['status'] === 'completed'));
        $this->assertNotNull($batches->firstWhere('batch_id', $completedId));
    }

    public function test_sc001_list_batches_denied_without_permission(): void
    {
        $this->actingAsAdminWithPermission('adminapp.users.read');
        $this->getJson('/v1/admin-app/reconciliation')->assertStatus(403);
    }

    // ── SC-002: Batch detail with unmatched IDs ──────────────────────────────

    public function test_sc002_show_batch_returns_correct_structure(): void
    {
        $batchId = $this->seedBatch();
        $this->actingAsAdminWithPermission('adminapp.reconciliation.read');

        $response = $this->getJson("/v1/admin-app/reconciliation/{$batchId}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.batch_id', $batchId);
        $response->assertJsonPath('data.status', 'completed');
        $this->assertIsArray($response->json('data.unmatched_transaction_ids'));
    }

    public function test_sc002_show_batch_includes_unmatched_transaction_ids(): void
    {
        $batchId = $this->seedBatch();
        $txId1   = (string) Uuid::uuid7();
        $txId2   = (string) Uuid::uuid7();
        $this->seedItem($batchId, 'unmatched', $txId1);
        $this->seedItem($batchId, 'exception', $txId2);

        $this->actingAsAdminWithPermission('adminapp.reconciliation.read');

        $response = $this->getJson("/v1/admin-app/reconciliation/{$batchId}");

        $response->assertStatus(200);
        $this->assertContains($txId1, $response->json('data.unmatched_transaction_ids'));
        $this->assertContains($txId2, $response->json('data.unmatched_transaction_ids'));
    }

    public function test_sc002_show_batch_returns_404_for_unknown_id(): void
    {
        $this->actingAsAdminWithPermission('adminapp.reconciliation.read');
        $this->getJson('/v1/admin-app/reconciliation/' . (string) Uuid::uuid7())->assertStatus(404);
    }

    // ── SC-003: List settlements ──────────────────────────────────────────────

    public function test_sc003_list_settlements_returns_paginated_list(): void
    {
        $settleId = $this->seedSettlement();
        $this->actingAsAdminWithPermission('adminapp.settlement.read');

        $response = $this->getJson('/v1/admin-app/settlement');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [['settlement_id', 'settlement_date', 'status', 'total_amount_minor_units', 'currency_code', 'net_position_minor_units', 'provider', 'reference']],
            'meta' => ['total', 'per_page', 'current_page', 'last_page'],
        ]);

        $settle = collect($response->json('data'))->firstWhere('settlement_id', $settleId);
        $this->assertNotNull($settle);
        $this->assertSame('pending_approval', $settle['status']);
        $this->assertSame(1000000, $settle['total_amount_minor_units']); // 10000.00 * 100
    }

    public function test_sc003_list_settlements_filters_by_status(): void
    {
        $this->seedSettlement('pending_approval');
        $executedId = $this->seedSettlement('executed');
        $this->actingAsAdminWithPermission('adminapp.settlement.read');

        $response = $this->getJson('/v1/admin-app/settlement?status=executed');

        $response->assertStatus(200);
        $settlements = collect($response->json('data'));
        $this->assertTrue($settlements->every(fn ($s) => $s['status'] === 'executed'));
        $this->assertNotNull($settlements->firstWhere('settlement_id', $executedId));
    }

    public function test_sc003_list_settlements_denied_without_permission(): void
    {
        $this->actingAsAdminWithPermission('adminapp.users.read');
        $this->getJson('/v1/admin-app/settlement')->assertStatus(403);
    }

    // ── SC-004: Settlement detail ─────────────────────────────────────────────

    public function test_sc004_show_settlement_returns_detail(): void
    {
        $settleId = $this->seedSettlement();
        $this->actingAsAdminWithPermission('adminapp.settlement.read');

        $response = $this->getJson("/v1/admin-app/settlement/{$settleId}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.settlement_id', $settleId);
        $response->assertJsonPath('data.status', 'pending_approval');
        $response->assertJsonPath('data.currency_code', 'BWP');
        $response->assertJsonPath('data.provider', 'BANCABC');
        $response->assertJsonPath('data.reference', 'REF-001');
    }

    public function test_sc004_show_settlement_returns_404_for_unknown_id(): void
    {
        $this->actingAsAdminWithPermission('adminapp.settlement.read');
        $this->getJson('/v1/admin-app/settlement/' . (string) Uuid::uuid7())->assertStatus(404);
    }

    // ── SC-005: Approve settlement ────────────────────────────────────────────

    public function test_sc005_approve_settlement_returns_approved_status(): void
    {
        $makerId  = (string) Uuid::uuid7();
        $settleId = $this->seedSettlement('pending_approval', $makerId);

        // Checker is authenticated as a different user (via actingAsAdminWithPermission)
        $this->actingAsAdminWithPermission('adminapp.settlement.read');

        $response = $this->postJson(
            "/v1/admin-app/settlement/{$settleId}/approve",
            [],
            ['Idempotency-Key' => (string) Uuid::uuid7()],
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.settlement_id', $settleId);
        $response->assertJsonPath('data.status', 'approved');
    }

    public function test_sc005_approve_settlement_returns_404_for_unknown_id(): void
    {
        $this->actingAsAdminWithPermission('adminapp.settlement.read');
        $this->postJson(
            '/v1/admin-app/settlement/' . (string) Uuid::uuid7() . '/approve',
            [],
            ['Idempotency-Key' => (string) Uuid::uuid7()],
        )->assertStatus(404);
    }

    public function test_sc005_approve_settlement_returns_422_for_non_pending_status(): void
    {
        $settleId = $this->seedSettlement('executed');
        $this->actingAsAdminWithPermission('adminapp.settlement.read');

        $this->postJson(
            "/v1/admin-app/settlement/{$settleId}/approve",
            [],
            ['Idempotency-Key' => (string) Uuid::uuid7()],
        )->assertStatus(422);
    }

    public function test_sc005_approve_settlement_requires_idempotency_key(): void
    {
        $settleId = $this->seedSettlement();
        $this->actingAsAdminWithPermission('adminapp.settlement.read');

        // RequireIdempotencyKey middleware returns 422 (ADMINAPP_IDEM_001) when header missing.
        $this->postJson(
            "/v1/admin-app/settlement/{$settleId}/approve",
            [],
        )->assertStatus(422);
    }

    public function test_sc005_approve_settlement_denied_without_permission(): void
    {
        $settleId = $this->seedSettlement();
        $this->actingAsAdminWithPermission('adminapp.users.read');

        $this->postJson(
            "/v1/admin-app/settlement/{$settleId}/approve",
            [],
            ['Idempotency-Key' => (string) Uuid::uuid7()],
        )->assertStatus(403);
    }

    // ── SC-006: Unauthenticated guards ────────────────────────────────────────

    public function test_sc006_list_reconciliation_requires_authentication(): void
    {
        $this->getJson('/v1/admin-app/reconciliation')->assertStatus(401);
    }

    public function test_sc006_list_settlement_requires_authentication(): void
    {
        $this->getJson('/v1/admin-app/settlement')->assertStatus(401);
    }
}
