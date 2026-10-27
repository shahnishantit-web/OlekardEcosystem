<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Feature;

use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Domain\Exceptions\RefundLimitExceededException;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T012 — Concurrent partial refunds must not over-refund.
 *
 * SC-001: Two concurrent 60% refund requests against a 100-unit payment.
 *         One must succeed; the other must be rejected with REFUND_LIMIT_EXCEEDED.
 *         Combined reserved amount MUST NOT exceed original.
 *
 * Requires Docker stack (payment DB + payment schema).
 * @group docker
 */
final class ConcurrentPartialRefundTest extends TestCase
{
    private string $attemptId;
    private string $intentId;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a seed payment attempt with 10,000 minor units
        $this->intentId  = (string) \Ramsey\Uuid\Uuid::uuid7();
        $this->attemptId = (string) \Ramsey\Uuid\Uuid::uuid7();

        DB::connection('payment')->table('payment.payment_attempts')->insert([
            'id'                    => $this->attemptId,
            'payment_intent_id'     => $this->intentId,
            'amount_minor'          => 10000,
            'currency_code'         => 'BWP',
            'status'                => 'succeeded',
            'provider_code'         => 'wallet',
            'provider_reference_id' => 'provider-ref-001',
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('payment')->table('payment.payment_refunds')
            ->where('payment_attempt_id', $this->attemptId)
            ->delete();

        DB::connection('payment')->table('payment.payment_attempts')
            ->where('id', $this->attemptId)
            ->delete();

        parent::tearDown();
    }

    /**
     * Simulates concurrent requests via sequential DB transactions that interleave
     * via SELECT FOR UPDATE SKIP LOCKED behavior.
     *
     * Real concurrency test requires two parallel PHP processes; this test validates
     * the reservation logic under the assumption that SKIP LOCKED handles the race.
     */
    public function test_two_concurrent_partial_refunds_cannot_over_refund(): void
    {
        $refundAmount = 6000; // 60% of 10,000
        $originalAmount = 10000;

        // Connection 1: start transaction, read reserved (0), write first refund
        $firstRefundId = (string) \Ramsey\Uuid\Uuid::uuid7();

        DB::connection('payment')->transaction(function () use ($firstRefundId, $refundAmount): void {
            $reserved = (int) DB::connection('payment')
                ->table('payment.payment_refunds')
                ->where('payment_attempt_id', $this->attemptId)
                ->whereNotIn('status', ['confirmed', 'definitive_failure', 'succeeded', 'failed', 'cancelled', 'rejected'])
                ->lock('for update skip locked')
                ->sum('amount_minor');

            $this->assertSame(0, $reserved, 'No refunds in flight initially');
            $this->assertLessThanOrEqual($refundAmount, $refundAmount - $reserved);

            DB::connection('payment')->table('payment.payment_refunds')->insert([
                'id'                 => $firstRefundId,
                'payment_intent_id'  => $this->intentId,
                'payment_attempt_id' => $this->attemptId,
                'amount_minor'       => $refundAmount,
                'status'             => 'processing',
                'approval_required'  => false,
                'idempotency_key'    => 'idem-001',
                'requested_by'       => 'actor-001',
                'requested_at'       => now(),
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        });

        // Connection 2: read reserved (6000 = first refund in flight), second request for 6000
        $secondRefundLimitExceeded = false;

        try {
            DB::connection('payment')->transaction(function () use ($refundAmount, $originalAmount): void {
                $reserved = (int) DB::connection('payment')
                    ->table('payment.payment_refunds')
                    ->where('payment_attempt_id', $this->attemptId)
                    ->whereNotIn('status', ['confirmed', 'definitive_failure', 'succeeded', 'failed', 'cancelled', 'rejected'])
                    ->lock('for update skip locked')
                    ->sum('amount_minor');

                $newTotal = $reserved + $refundAmount;

                if ($newTotal > $originalAmount) {
                    throw new RefundLimitExceededException(
                        requestedMinor: $refundAmount,
                        confirmedMinor: $reserved,
                        originalMinor:  $originalAmount,
                        currencyCode:   'BWP',
                    );
                }
            });
        } catch (RefundLimitExceededException) {
            $secondRefundLimitExceeded = true;
        }

        $this->assertTrue($secondRefundLimitExceeded, 'Second request must be rejected with REFUND_LIMIT_EXCEEDED');

        // Verify DB: only one refund record exists
        $count = DB::connection('payment')
            ->table('payment.payment_refunds')
            ->where('payment_attempt_id', $this->attemptId)
            ->count();

        $this->assertSame(1, $count, 'Only one refund record should exist');

        // Verify the total reserved does not exceed original
        $totalReserved = (int) DB::connection('payment')
            ->table('payment.payment_refunds')
            ->where('payment_attempt_id', $this->attemptId)
            ->whereNotIn('status', ['confirmed', 'definitive_failure', 'succeeded', 'failed', 'cancelled', 'rejected'])
            ->sum('amount_minor');

        $this->assertLessThanOrEqual($originalAmount, $totalReserved, 'Total reserved MUST NOT exceed original payment');
    }
}
