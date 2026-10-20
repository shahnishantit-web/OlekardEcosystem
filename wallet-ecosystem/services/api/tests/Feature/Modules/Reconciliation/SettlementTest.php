<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Reconciliation;

use App\Modules\Reconciliation\Domain\Enums\BatchStatus;
use App\Modules\Reconciliation\Domain\Enums\ItemStatus;
use App\Modules\Reconciliation\Domain\Models\ReconciliationBatch;
use App\Modules\Reconciliation\Domain\Models\ReconciliationItem;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;
use Tests\TestCase;

/**
 * T056 — E20 Settlement feature tests (Docker-gated).
 *
 * Run: DB_DATABASE=wallet_ecosystem_test php artisan test tests/Feature/Modules/Reconciliation/SettlementTest.php
 */
final class SettlementTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<string> */
    protected $connectionsToTransact = [
        'platform', 'identity', 'profile', 'wallet', 'ledger', 'kyc',
        'transaction', 'risk', 'agent', 'notification', 'funding', 'transfer',
        'reconciliation',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /**
     * Create a batch with matched items ready for settlement.
     *
     * @return array{batch: ReconciliationBatch, items: list<ReconciliationItem>}
     */
    private function makeMatchedBatchWithItems(int $count = 2): array
    {
        $batchId = (string) Str::orderedUuid();

        $batch = ReconciliationBatch::on('reconciliation')->create([
            'id'               => $batchId,
            'idempotency_key'  => (string) Str::uuid(),
            'source_type'      => 'provider_file',
            'source_reference' => 'test-ref-' . Str::random(8),
            'batch_date'       => '2026-09-25',
            'status'           => BatchStatus::Matched->value,
            'total_count'      => $count,
            'matched_count'    => $count,
            'submitted_by'     => (string) Str::uuid(),
            'metadata'         => [],
        ]);

        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = ReconciliationItem::on('reconciliation')->create([
                'id'                 => (string) Str::orderedUuid(),
                'batch_id'           => $batchId,
                'item_type'          => 'provider_transaction',
                'provider_reference' => 'REF-' . Str::random(8),
                'amount'             => '100.0000',
                'currency_code'      => 'BWP',
                'transaction_date'   => '2026-09-25',
                'status'             => ItemStatus::Matched->value,
                'match_confidence'   => 100,
                'metadata'           => [],
            ]);
        }

        return ['batch' => $batch, 'items' => $items];
    }

    private function settlementPayload(string $batchId, array $overrides = []): array
    {
        return array_merge([
            'recon_batch_id'  => $batchId,
            'settlement_type' => 'provider_settlement',
            'period_start'    => '2026-09-01',
            'period_end'      => '2026-09-30',
            'currency_code'   => 'BWP',
        ], $overrides);
    }

    // ── SC-6: full lifecycle — create → submit → approve → execute ────────────

    public function test_sc6_full_settlement_lifecycle(): void
    {
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

        $maker   = $this->createAuthenticatedAdmin(['reconciliation.write']);
        $checker = $this->createAuthenticatedAdmin([
            'reconciliation.approve_settlement',
            'reconciliation.execute_settlement',
        ]);

        ['batch' => $batch] = $this->makeMatchedBatchWithItems(2);

        // Step 1: Create settlement (maker)
        $idemKey1   = (string) Str::uuid();
        $makeResult = $this->actingAs($maker)
            ->withHeaders(['X-Test-User-Id' => $maker->id, 'Idempotency-Key' => $idemKey1])
            ->postJson('/v1/reconciliation/settlements',
                $this->settlementPayload($batch->id));

        $makeResult->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.currency_code', 'BWP');

        $settlementId = $makeResult->json('data.id');

        // Step 2: Submit for approval (maker)
        $submitResult = $this->actingAs($maker)
            ->withHeaders(['X-Test-User-Id' => $maker->id])
            ->postJson("/v1/reconciliation/settlements/{$settlementId}/submit");

        $submitResult->assertOk()
            ->assertJsonPath('data.status', 'pending_approval');

        // Step 3: Approve (checker — different user)
        $idemKey3     = (string) Str::uuid();
        $approveResult = $this->actingAs($checker)
            ->withHeaders(['X-Test-User-Id' => $checker->id, 'Idempotency-Key' => $idemKey3])
            ->postJson("/v1/reconciliation/settlements/{$settlementId}/approve");

        $approveResult->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // Step 4: Execute (checker has execute_settlement permission)
        $idemKey4     = (string) Str::uuid();
        $executeResult = $this->actingAs($checker)
            ->withHeaders(['X-Test-User-Id' => $checker->id, 'Idempotency-Key' => $idemKey4])
            ->postJson("/v1/reconciliation/settlements/{$settlementId}/execute");

        $executeResult->assertStatus(202)
            ->assertJsonPath('data.status', 'executing');
    }

    // ── SC-7: idempotent create — same Idempotency-Key → same settlement ID ───

    public function test_sc7_idempotent_create_returns_same_settlement_id(): void
    {
        $admin = $this->createAuthenticatedAdmin(['reconciliation.write']);

        ['batch' => $batch] = $this->makeMatchedBatchWithItems(1);
        $idemKey = (string) Str::uuid();

        $first = $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => $idemKey])
            ->postJson('/v1/reconciliation/settlements',
                $this->settlementPayload($batch->id));
        $first->assertCreated();
        $settlementId = $first->json('data.id');

        $second = $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => $idemKey])
            ->postJson('/v1/reconciliation/settlements',
                $this->settlementPayload($batch->id));
        $second->assertCreated()
            ->assertJsonPath('data.id', $settlementId);
    }

    // ── Missing Idempotency-Key → 422 ────────────────────────────────────────

    public function test_create_without_idempotency_key_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['reconciliation.write']);

        ['batch' => $batch] = $this->makeMatchedBatchWithItems(1);

        $response = $this->actingAs($admin)
            ->postJson('/v1/reconciliation/settlements',
                $this->settlementPayload($batch->id));

        $response->assertStatus(422);
    }

    // ── Submit already-submitted settlement → 409 ────────────────────────────

    public function test_submit_already_submitted_settlement_returns_409(): void
    {
        $admin = $this->createAuthenticatedAdmin(['reconciliation.write']);

        ['batch' => $batch] = $this->makeMatchedBatchWithItems(1);
        $idemKey = (string) Str::uuid();

        $createResponse = $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => $idemKey])
            ->postJson('/v1/reconciliation/settlements',
                $this->settlementPayload($batch->id));
        $createResponse->assertCreated();
        $settlementId = $createResponse->json('data.id');

        $this->actingAs($admin)
            ->postJson("/v1/reconciliation/settlements/{$settlementId}/submit")
            ->assertOk();

        $this->actingAs($admin)
            ->postJson("/v1/reconciliation/settlements/{$settlementId}/submit")
            ->assertStatus(409);
    }

    // ── Approve: maker cannot approve own settlement → 422 ───────────────────

    public function test_maker_cannot_approve_own_settlement_returns_422(): void
    {
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

        $maker = $this->createAuthenticatedAdmin([
            'reconciliation.write',
            'reconciliation.approve_settlement',
        ]);

        ['batch' => $batch] = $this->makeMatchedBatchWithItems(1);
        $idemKey = (string) Str::uuid();

        $createResponse = $this->actingAs($maker)
            ->withHeaders(['X-Test-User-Id' => $maker->id, 'Idempotency-Key' => $idemKey])
            ->postJson('/v1/reconciliation/settlements',
                $this->settlementPayload($batch->id));
        $createResponse->assertCreated();
        $settlementId = $createResponse->json('data.id');

        $this->actingAs($maker)
            ->withHeaders(['X-Test-User-Id' => $maker->id])
            ->postJson("/v1/reconciliation/settlements/{$settlementId}/submit")
            ->assertOk();

        // Maker tries to approve own settlement → 422 (maker-checker policy)
        $this->actingAs($maker)
            ->withHeaders([
                'X-Test-User-Id'  => $maker->id,
                'Idempotency-Key' => (string) Str::uuid(),
            ])
            ->postJson("/v1/reconciliation/settlements/{$settlementId}/approve")
            ->assertStatus(422);
    }

    // ── Auth: customer cannot create settlement → 403 ────────────────────────

    public function test_customer_cannot_create_settlement_returns_403(): void
    {
        $customer = $this->createAuthenticatedUser([]);

        ['batch' => $batch] = $this->makeMatchedBatchWithItems(1);

        $response = $this->actingAs($customer)
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/v1/reconciliation/settlements',
                $this->settlementPayload($batch->id));

        $response->assertStatus(403);
    }

    // ── Auth: no token → 401 ─────────────────────────────────────────────────

    public function test_unauthenticated_cannot_list_settlements_returns_401(): void
    {
        $response = $this->getJson('/v1/reconciliation/settlements');

        $response->assertStatus(401);
    }

    // ── List settlements → 200 ───────────────────────────────────────────────

    public function test_list_settlements_returns_paginated_results(): void
    {
        $admin = $this->createAuthenticatedAdmin(['reconciliation.read']);

        $response = $this->actingAs($admin)->getJson('/v1/reconciliation/settlements');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }
}
