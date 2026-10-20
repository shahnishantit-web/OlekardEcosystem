<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Transfer;

use App\Modules\Transfer\Application\Jobs\TransferTransactionTimeoutJob;
use App\Modules\Transfer\Domain\Enums\TransferStatus;
use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * E27 — FR-004, US3: TransferTransactionTimeoutJob transitions stuck pending
 * transfer transactions beyond the orphan threshold to timeout_pending_review.
 */
final class TransferTransactionTimeoutTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = [null, 'platform'];

    private function seedTransferTransaction(string $status, \DateTimeInterface $updatedAt): TransferTransactionModel
    {
        $tx = new TransferTransactionModel([
            'id'                    => (string) Uuid::uuid7(),
            'sender_customer_id'    => (string) Uuid::uuid7(),
            'sender_wallet_id'      => (string) Uuid::uuid7(),
            'recipient_customer_id' => (string) Uuid::uuid7(),
            'recipient_wallet_id'   => (string) Uuid::uuid7(),
            'currency_code'         => 'BWP',
            'gross_amount'          => 5000,
            'fee_amount'            => 50,
            'net_amount'            => 4950,
            'status'                => $status,
            'idempotency_key'       => (string) Uuid::uuid7(),
            'correlation_id'        => (string) Uuid::uuid7(),
        ]);
        $tx->save();

        // Force updated_at to the given timestamp
        TransferTransactionModel::withoutTimestamps(function () use ($tx, $updatedAt): void {
            $tx->updated_at = $updatedAt;
            $tx->save();
        });

        return $tx->fresh();
    }

    // ── Test 1: Pending transaction beyond threshold is transitioned ──────────

    public function test_pending_transaction_beyond_threshold_is_transitioned(): void
    {
        $tx = $this->seedTransferTransaction(
            status:    TransferStatus::Pending->value,
            updatedAt: now()->subMinutes(10),
        );

        dispatch_sync(new TransferTransactionTimeoutJob(correlationId: (string) Uuid::uuid7()));

        $this->assertSame(
            TransferStatus::TimeoutPendingReview->value,
            $tx->fresh()->status->value,
            'Transfer transaction updated_at 10 minutes ago must be transitioned to timeout_pending_review'
        );
    }

    // ── Test 2: Transaction within threshold is NOT transitioned ─────────────

    public function test_pending_transaction_within_threshold_is_not_transitioned(): void
    {
        $tx = $this->seedTransferTransaction(
            status:    TransferStatus::Pending->value,
            updatedAt: now()->subMinutes(2),
        );

        dispatch_sync(new TransferTransactionTimeoutJob(correlationId: (string) Uuid::uuid7()));

        $this->assertSame(
            TransferStatus::Pending->value,
            $tx->fresh()->status->value,
            'Transfer transaction updated_at 2 minutes ago must NOT be transitioned'
        );
    }

    // ── Test 3: Terminal transactions are untouched ───────────────────────────

    public function test_completed_transfer_is_not_affected(): void
    {
        $tx = $this->seedTransferTransaction(
            status:    TransferStatus::Completed->value,
            updatedAt: now()->subMinutes(60),
        );

        dispatch_sync(new TransferTransactionTimeoutJob(correlationId: (string) Uuid::uuid7()));

        $this->assertSame(
            TransferStatus::Completed->value,
            $tx->fresh()->status->value,
            'Completed transfers must never be touched by the timeout job'
        );
    }
}
