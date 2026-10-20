<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Reconciliation;

use App\Modules\Reconciliation\Domain\Enums\BatchStatus;
use App\Modules\Reconciliation\Domain\Enums\ExceptionStatus;
use App\Modules\Reconciliation\Domain\Enums\ExceptionType;
use App\Modules\Reconciliation\Domain\Enums\ItemStatus;
use App\Modules\Reconciliation\Domain\Models\ReconciliationBatch;
use App\Modules\Reconciliation\Domain\Models\ReconciliationException;
use App\Modules\Reconciliation\Domain\Models\ReconciliationItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T047 — E20 Reconciliation matching & exception feature tests (Docker-gated).
 *
 * Run: DB_DATABASE=wallet_ecosystem_test php artisan test tests/Feature/Modules/Reconciliation/MatchingExceptionTest.php
 */
final class MatchingExceptionTest extends TestCase
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

    private function makeMatchedBatch(): ReconciliationBatch
    {
        return ReconciliationBatch::on('reconciliation')->create([
            'id'               => (string) Str::orderedUuid(),
            'idempotency_key'  => (string) Str::uuid(),
            'source_type'      => 'provider_file',
            'source_reference' => 'test-ref-' . Str::random(8),
            'batch_date'       => '2026-09-25',
            'status'           => BatchStatus::Matched->value,
            'total_count'      => 2,
            'matched_count'    => 1,
            'unmatched_count'  => 1,
            'submitted_by'     => (string) Str::uuid(),
            'metadata'         => [],
        ]);
    }

    private function makeItem(string $batchId, string $status = 'unmatched'): ReconciliationItem
    {
        return ReconciliationItem::on('reconciliation')->create([
            'id'                 => (string) Str::orderedUuid(),
            'batch_id'           => $batchId,
            'item_type'          => 'provider_transaction',
            'provider_reference' => 'REF-' . Str::random(8),
            'amount'             => '100.0000',
            'currency_code'      => 'BWP',
            'transaction_date'   => '2026-09-25',
            'status'             => $status,
            'match_confidence'   => 0,
            'metadata'           => [],
        ]);
    }

    private function makeException(string $itemId, string $batchId): ReconciliationException
    {
        return ReconciliationException::on('reconciliation')->create([
            'id'             => (string) Str::orderedUuid(),
            'item_id'        => $itemId,
            'batch_id'       => $batchId,
            'exception_type' => ExceptionType::UnmatchedProvider->value,
            'status'         => ExceptionStatus::Open->value,
            'opened_by'      => (string) Str::uuid(),
        ]);
    }

    private function resolvePayload(array $overrides = []): array
    {
        return array_merge([
            'resolution_type' => 'manually_matched',
            'resolution'      => 'Manually matched after investigation.',
        ], $overrides);
    }

    // ── SC-3: resolve exception → 200; re-resolve resolved exception → 422 ───

    public function test_sc3_resolve_exception_returns_200(): void
    {
        $admin = $this->createAuthenticatedAdmin(['reconciliation.write']);

        $batch     = $this->makeMatchedBatch();
        $item      = $this->makeItem($batch->id, ItemStatus::Exception->value);
        $exception = $this->makeException($item->id, $batch->id);

        $response = $this->actingAs($admin)
            ->postJson("/v1/reconciliation/exceptions/{$exception->id}/resolve",
                $this->resolvePayload());

        $response->assertOk()
            ->assertJsonPath('data.status', 'resolved');
    }

    public function test_sc3_re_resolve_already_resolved_exception_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['reconciliation.write']);

        $batch     = $this->makeMatchedBatch();
        $item      = $this->makeItem($batch->id, ItemStatus::Exception->value);
        $exception = $this->makeException($item->id, $batch->id);

        // First resolve
        $this->actingAs($admin)
            ->postJson("/v1/reconciliation/exceptions/{$exception->id}/resolve",
                $this->resolvePayload())
            ->assertOk();

        // Second resolve of same exception → 422 (already resolved)
        $this->actingAs($admin)
            ->postJson("/v1/reconciliation/exceptions/{$exception->id}/resolve",
                $this->resolvePayload())
            ->assertStatus(422);
    }

    // ── SC-4: mark unmatched item as reconciled → 200 ────────────────────────

    public function test_sc4_mark_reconciled_returns_200(): void
    {
        $admin  = $this->createAuthenticatedAdmin(['reconciliation.write']);
        $batch  = $this->makeMatchedBatch();
        $item   = $this->makeItem($batch->id, ItemStatus::Unmatched->value);

        $response = $this->actingAs($admin)
            ->postJson("/v1/reconciliation/items/{$item->id}/mark-reconciled");

        $response->assertOk()
            ->assertJsonPath('data.status', 'matched');
    }

    public function test_mark_already_matched_item_returns_200_idempotent(): void
    {
        $admin = $this->createAuthenticatedAdmin(['reconciliation.write']);
        $batch = $this->makeMatchedBatch();
        $item  = $this->makeItem($batch->id, ItemStatus::Matched->value);

        $response = $this->actingAs($admin)
            ->postJson("/v1/reconciliation/items/{$item->id}/mark-reconciled");

        $response->assertOk()
            ->assertJsonPath('data.status', 'matched');
    }

    // ── Trigger match on processing batch → 422 ──────────────────────────────

    public function test_trigger_match_on_processing_batch_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['reconciliation.write']);

        $batch = ReconciliationBatch::on('reconciliation')->create([
            'id'               => (string) Str::orderedUuid(),
            'idempotency_key'  => (string) Str::uuid(),
            'source_type'      => 'provider_file',
            'source_reference' => 'test-ref-' . Str::random(8),
            'batch_date'       => '2026-09-25',
            'status'           => BatchStatus::Processing->value,
            'total_count'      => 1,
            'submitted_by'     => (string) Str::uuid(),
            'metadata'         => [],
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/v1/reconciliation/batches/{$batch->id}/match");

        $response->assertStatus(422);
    }

    // ── Trigger match on completed batch → 202 ───────────────────────────────

    public function test_trigger_match_on_completed_batch_returns_202(): void
    {
        $admin = $this->createAuthenticatedAdmin(['reconciliation.write']);
        $batch = $this->makeMatchedBatch();

        $response = $this->actingAs($admin)
            ->postJson("/v1/reconciliation/batches/{$batch->id}/match");

        $response->assertStatus(202);
    }

    // ── List unmatched items ──────────────────────────────────────────────────

    public function test_list_unmatched_returns_only_unmatched_items(): void
    {
        $admin = $this->createAuthenticatedAdmin(['reconciliation.read']);

        $batch        = $this->makeMatchedBatch();
        $unmatchedItem = $this->makeItem($batch->id, ItemStatus::Unmatched->value);
        $this->makeItem($batch->id, ItemStatus::Matched->value);

        $response = $this->actingAs($admin)
            ->getJson("/v1/reconciliation/batches/{$batch->id}/unmatched");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($unmatchedItem->id, $ids);
        $this->assertCount(1, $ids);
    }

    // ── Resolve exception: missing resolution fields → 422 ───────────────────

    public function test_resolve_exception_missing_resolution_type_returns_422(): void
    {
        $admin     = $this->createAuthenticatedAdmin(['reconciliation.write']);
        $batch     = $this->makeMatchedBatch();
        $item      = $this->makeItem($batch->id, ItemStatus::Exception->value);
        $exception = $this->makeException($item->id, $batch->id);

        $response = $this->actingAs($admin)
            ->postJson("/v1/reconciliation/exceptions/{$exception->id}/resolve", [
                'resolution' => 'Some resolution note.',
            ]);

        $response->assertStatus(422);
    }

    public function test_resolve_exception_missing_resolution_text_returns_422(): void
    {
        $admin     = $this->createAuthenticatedAdmin(['reconciliation.write']);
        $batch     = $this->makeMatchedBatch();
        $item      = $this->makeItem($batch->id, ItemStatus::Exception->value);
        $exception = $this->makeException($item->id, $batch->id);

        $response = $this->actingAs($admin)
            ->postJson("/v1/reconciliation/exceptions/{$exception->id}/resolve", [
                'resolution_type' => 'manually_matched',
            ]);

        $response->assertStatus(422);
    }

    // ── Auth: customer cannot resolve exceptions → 403 ───────────────────────

    public function test_customer_cannot_resolve_exception_returns_403(): void
    {
        $customer  = $this->createAuthenticatedUser([]);
        $batch     = $this->makeMatchedBatch();
        $item      = $this->makeItem($batch->id, ItemStatus::Exception->value);
        $exception = $this->makeException($item->id, $batch->id);

        $response = $this->actingAs($customer)
            ->postJson("/v1/reconciliation/exceptions/{$exception->id}/resolve",
                $this->resolvePayload());

        $response->assertStatus(403);
    }

    // ── Auth: no token → 401 ─────────────────────────────────────────────────

    public function test_unauthenticated_cannot_list_exceptions_returns_401(): void
    {
        $response = $this->getJson('/v1/reconciliation/exceptions');

        $response->assertStatus(401);
    }
}
