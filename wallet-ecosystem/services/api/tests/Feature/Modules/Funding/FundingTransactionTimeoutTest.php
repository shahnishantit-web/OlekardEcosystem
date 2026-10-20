<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Funding;

use App\Modules\Funding\Application\Jobs\FundingTransactionTimeoutJob;
use App\Modules\Funding\Domain\Enums\FundingStatus;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * E27 — FR-004, US3: FundingTransactionTimeoutJob transitions stuck pending_provider
 * transactions beyond the orphan threshold to timeout_pending_review.
 */
final class FundingTransactionTimeoutTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = [null, 'platform'];

    private function seedFundingTransaction(string $status, \DateTimeInterface $updatedAt): FundingTransactionModel
    {
        $tx = new FundingTransactionModel([
            'id'                    => (string) Uuid::uuid7(),
            'wallet_id'             => (string) Uuid::uuid7(),
            'pocket_currency_code'  => 'BWP',
            'customer_id'           => (string) Uuid::uuid7(),
            'funding_method'        => 'bank_transfer',
            'status'                => $status,
            'amount'                => 10000,
            'fee_amount'            => 100,
            'net_amount'            => 9900,
            'idempotency_key'       => (string) Uuid::uuid7(),
            'transaction_reference' => (string) Uuid::uuid7(),
            'initiated_by'          => (string) Uuid::uuid7(),
        ]);
        $tx->save();

        // Force the updated_at to the given timestamp
        FundingTransactionModel::withoutTimestamps(function () use ($tx, $updatedAt): void {
            $tx->updated_at = $updatedAt;
            $tx->save();
        });

        return $tx->fresh();
    }

    // ── Test 1: Orphan beyond threshold is transitioned ───────────────────────

    public function test_pending_provider_transaction_beyond_threshold_is_transitioned(): void
    {
        $tx = $this->seedFundingTransaction(
            status:    FundingStatus::PendingProvider->value,
            updatedAt: now()->subMinutes(10),
        );

        dispatch_sync(new FundingTransactionTimeoutJob(correlationId: (string) Uuid::uuid7()));

        $this->assertSame(
            FundingStatus::TimeoutPendingReview->value,
            $tx->fresh()->status->value,
            'Transaction updated_at 10 minutes ago must be transitioned to timeout_pending_review'
        );
    }

    // ── Test 2: Transaction within threshold is NOT transitioned ─────────────

    public function test_pending_provider_transaction_within_threshold_is_not_transitioned(): void
    {
        $tx = $this->seedFundingTransaction(
            status:    FundingStatus::PendingProvider->value,
            updatedAt: now()->subMinutes(2),
        );

        dispatch_sync(new FundingTransactionTimeoutJob(correlationId: (string) Uuid::uuid7()));

        $this->assertSame(
            FundingStatus::PendingProvider->value,
            $tx->fresh()->status->value,
            'Transaction updated_at 2 minutes ago must NOT be transitioned (within default 5-minute threshold)'
        );
    }

    // ── Test 3: Non-pending_provider transactions are not affected ────────────

    public function test_completed_transaction_is_not_affected(): void
    {
        $tx = $this->seedFundingTransaction(
            status:    FundingStatus::Completed->value,
            updatedAt: now()->subMinutes(60),
        );

        dispatch_sync(new FundingTransactionTimeoutJob(correlationId: (string) Uuid::uuid7()));

        $this->assertSame(
            FundingStatus::Completed->value,
            $tx->fresh()->status->value,
            'Completed transactions must never be touched by the timeout job'
        );
    }
}
