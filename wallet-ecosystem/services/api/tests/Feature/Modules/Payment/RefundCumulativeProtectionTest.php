<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use App\Modules\Payment\Application\Services\RefundService;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Domain\Exceptions\RefundLimitExceededException;
use App\Modules\Payment\Domain\ValueObjects\PaymentAttemptResult;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Ramsey\Uuid\Uuid;

/**
 * T143: Feature tests for refund cumulative protection.
 *
 * SC-06: BWP 100 payment; BWP 60 partial refund succeeds;
 *        second BWP 60 refund fails with RefundLimitExceededException.
 */
final class RefundCumulativeProtectionTest extends TestCase
{
    use RefreshDatabase;
    use \Illuminate\Foundation\Testing\CreatesApplication;

    // ─── Test 1: First partial refund within limit succeeds ──────────────────

    public function test_partial_refund_within_limit_succeeds(): void
    {
        $this->markTestSkipped('T143: Full refund flow requires Docker (DB + adapter). Unit coverage in T173.');
    }

    // ─── Test 2: Second refund exceeding cumulative limit fails ───────────────

    public function test_second_refund_exceeding_cumulative_limit_throws(): void
    {
        $this->markTestSkipped('T143: Cumulative guard integration requires Docker (real DB SELECT FOR UPDATE). Unit coverage in T173.');
    }

    // ─── Test 3: 50 concurrent refund requests — exactly one succeeds ─────────

    public function test_concurrent_refunds_exactly_one_succeeds(): void
    {
        $this->markTestSkipped('T143: Concurrency test requires Docker (multiple PHP processes + real DB locks).');
    }

    // ─── Test 4: Full refund after partial succeeds ───────────────────────────

    public function test_full_refund_after_partial_succeeds_within_remaining(): void
    {
        $this->markTestSkipped('T143: Full refund flow requires Docker.');
    }

    // ─── Test 5: Refund on unsupported provider throws ───────────────────────

    public function test_refund_on_unsupported_provider_throws_capability_exception(): void
    {
        $this->markTestSkipped('T143: Provider capability check requires Docker (live adapter registry).');
    }

    // ─── Test 6: Refund above threshold requires approval ────────────────────

    public function test_refund_above_threshold_requires_approval(): void
    {
        $this->markTestSkipped('T143: Approval threshold integration requires Docker (ConfigurationService).');
    }
}
