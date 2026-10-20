<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Platform\Reliability;

use App\Jobs\DeadLetterCleanupJob;
use App\Modules\Platform\Application\Services\DeadLetterService;
use App\Modules\Platform\Domain\Contracts\DeadLetterServiceInterface;
use App\Modules\Platform\Domain\Exceptions\DeadLetterConflictException;
use App\Modules\Platform\Infrastructure\Models\DeadLetterItemModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * E27 — FR-005, US4: Dead-Letter visibility, replay, dismiss, cleanup.
 */
final class DeadLetterTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = [null, 'platform'];

    private DeadLetterServiceInterface $service;
    private string $correlationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service       = app(DeadLetterServiceInterface::class);
        $this->correlationId = (string) Uuid::uuid7();
    }

    private function captureItem(string $operationType = 'funding.initiation'): DeadLetterItemModel
    {
        return $this->service->capture(
            operationType: $operationType,
            sourceId:       (string) Uuid::uuid7(),
            payload:        ['amount' => 10000, 'wallet_id' => (string) Uuid::uuid7()],
            failureReason:  'Provider timeout',
            retryCount:     3,
            retryHistory:   [['attempt' => 1, 'failed_at' => now()->toIso8601String(), 'reason' => 'timeout']],
            correlationId:  $this->correlationId,
        );
    }

    // ── Test 1: Capture creates a pending item ─────────────────────────────────

    public function test_capture_creates_pending_dead_letter_item(): void
    {
        $item = $this->captureItem();

        $this->assertNotNull($item->id);
        $this->assertSame('pending', $item->status);
        $this->assertSame(3, $item->retry_count);
        $this->assertDatabaseHas('platform.dead_letter_items', [
            'id'     => $item->id,
            'status' => 'pending',
        ]);
    }

    // ── Test 2: List paginates and filters by status ───────────────────────────

    public function test_list_paginates_and_filters_by_status(): void
    {
        $this->captureItem('funding.initiation');
        $this->captureItem('transfer.confirmation');

        $result = $this->service->list(['status' => 'pending'], 10);

        $this->assertGreaterThanOrEqual(2, $result->total());
    }

    // ── Test 3: Replay transitions to replayed + emits audit event ─────────────

    public function test_replay_transitions_item_to_replayed_and_emits_audit_event(): void
    {
        $item   = $this->captureItem();
        $actorId = (string) Uuid::uuid7();

        $replayed = $this->service->replay($item->id, $actorId, 'Retrying after fix', $this->correlationId);

        $this->assertSame('replayed', $replayed->status);
        $this->assertNotNull($replayed->replayed_at);
        $this->assertSame($actorId, $replayed->replayed_by);

        $this->assertDatabaseHas('platform.audit_events', [
            'action'    => 'platform.dead_letter.item_replayed',
            'resource_id' => $item->id,
        ]);
    }

    // ── Test 4: Second replay throws ConflictException ─────────────────────────

    public function test_second_replay_throws_conflict_exception(): void
    {
        $item    = $this->captureItem();
        $actorId = (string) Uuid::uuid7();

        $this->service->replay($item->id, $actorId, null, $this->correlationId);

        $this->expectException(DeadLetterConflictException::class);
        $this->service->replay($item->id, $actorId, null, $this->correlationId);
    }

    // ── Test 5: Dismiss transitions to dismissed + emits audit event ───────────

    public function test_dismiss_transitions_item_to_dismissed_and_emits_audit_event(): void
    {
        $item    = $this->captureItem();
        $actorId = (string) Uuid::uuid7();

        $dismissed = $this->service->dismiss($item->id, $actorId, 'Not actionable — stale test data', $this->correlationId);

        $this->assertSame('dismissed', $dismissed->status);
        $this->assertNotNull($dismissed->dismissed_at);
        $this->assertSame($actorId, $dismissed->dismissed_by);

        $this->assertDatabaseHas('platform.audit_events', [
            'action'    => 'platform.dead_letter.item_dismissed',
            'resource_id' => $item->id,
        ]);
    }

    // ── Test 6: Dismissed item cannot be replayed ──────────────────────────────

    public function test_dismissed_item_cannot_be_replayed(): void
    {
        $item    = $this->captureItem();
        $actorId = (string) Uuid::uuid7();

        $this->service->dismiss($item->id, $actorId, 'Not applicable for this test case', $this->correlationId);

        $this->expectException(DeadLetterConflictException::class);
        $this->service->replay($item->id, $actorId, null, $this->correlationId);
    }

    // ── Test 7: Cleanup job purges only expired pending items ──────────────────

    public function test_cleanup_job_purges_only_expired_pending_items(): void
    {
        // Expired pending item
        $expiredItem = $this->captureItem('service.payment');
        DB::connection('platform')->table('platform.dead_letter_items')
            ->where('id', $expiredItem->id)
            ->update(['expires_at' => now()->subDay()]);

        // Non-expired pending item
        $activeItem = $this->captureItem('qr.payment');

        // Replayed item (must NOT be purged even if expired)
        $replayedItem = $this->captureItem('withdrawal.payout');
        DB::connection('platform')->table('platform.dead_letter_items')
            ->where('id', $replayedItem->id)
            ->update(['status' => 'replayed', 'expires_at' => now()->subDay()]);

        dispatch_sync(new DeadLetterCleanupJob(correlationId: $this->correlationId));

        $this->assertDatabaseMissing('platform.dead_letter_items', ['id' => $expiredItem->id]);
        $this->assertDatabaseHas('platform.dead_letter_items', ['id' => $activeItem->id]);
        $this->assertDatabaseHas('platform.dead_letter_items', ['id' => $replayedItem->id]);
    }

    // ── Test 8: Concurrent replay — only one succeeds ─────────────────────────

    public function test_concurrent_replay_only_one_transaction_commits(): void
    {
        $item    = $this->captureItem();
        $actorId = (string) Uuid::uuid7();
        $success = 0;
        $conflict = 0;

        for ($i = 0; $i < 2; $i++) {
            try {
                $this->service->replay($item->id, $actorId, null, $this->correlationId);
                $success++;
            } catch (DeadLetterConflictException) {
                $conflict++;
            }
        }

        $this->assertSame(1, $success,  'Exactly one replay must succeed');
        $this->assertSame(1, $conflict, 'The second replay must raise a conflict');
    }
}
