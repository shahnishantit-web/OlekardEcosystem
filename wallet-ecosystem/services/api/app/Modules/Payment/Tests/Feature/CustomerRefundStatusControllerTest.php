<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Feature;

use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * T029: US6 — Mobile consumer can read refund statuses for their own payment intents.
 *
 * @group docker
 *
 * - (a) Owner fetches own refunds → 200 with correct payload
 * - (b) confirmed → funds_returned = true
 * - (c) pending/unknown → funds_returned = false, non-committal label
 * - (d) non-owner → 403
 * - (e) multiple partial refunds → correct total_confirmed_minor and remaining_refundable_minor
 */
final class CustomerRefundStatusControllerTest extends TestCase
{
    private MockObject&PaymentIntentRepositoryInterface $intentRepo;
    private MockObject&PaymentRefundRepositoryInterface $refundRepo;

    private const INTENT_ID     = 'intent-abc-001';
    private const INTENT_AMOUNT = 10000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->intentRepo = $this->createMock(PaymentIntentRepositoryInterface::class);
        $this->refundRepo = $this->createMock(PaymentRefundRepositoryInterface::class);

        $this->app->instance(PaymentIntentRepositoryInterface::class, $this->intentRepo);
        $this->app->instance(PaymentRefundRepositoryInterface::class, $this->refundRepo);
    }

    // ─── (a) Owner fetches own refunds → 200 ────────────────────────────────

    public function test_owner_can_list_own_refunds(): void
    {
        $owner = $this->createAuthenticatedUser(['payment.refunds.view']);
        $this->actingAs($owner);

        $this->intentRepo->method('findById')->willReturn($this->makeIntent($owner->id));
        $this->refundRepo->method('findByIntentId')->willReturn([
            $this->makeRefund(PaymentRefundStatus::Confirmed, 3000),
        ]);

        $response = $this->getJson("/api/v1/payment/intents/" . self::INTENT_ID . "/refunds");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [['id', 'status', 'status_label', 'amount_minor', 'funds_returned']],
            'meta' => ['total_confirmed_minor', 'remaining_refundable_minor', 'has_pending_refunds'],
        ]);
    }

    // ─── (b) confirmed → funds_returned = true ──────────────────────────────

    public function test_confirmed_refund_has_funds_returned_true(): void
    {
        $owner = $this->createAuthenticatedUser(['payment.refunds.view']);
        $this->actingAs($owner);

        $this->intentRepo->method('findById')->willReturn($this->makeIntent($owner->id));
        $this->refundRepo->method('findByIntentId')->willReturn([
            $this->makeRefund(PaymentRefundStatus::Confirmed, 3000),
        ]);

        $response = $this->getJson("/api/v1/payment/intents/" . self::INTENT_ID . "/refunds");

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.funds_returned', true);
        $response->assertJsonPath('data.0.status_label', 'Funds returned');
    }

    // ─── (c) pending → funds_returned = false, non-committal label ──────────

    public function test_pending_refund_has_funds_returned_false(): void
    {
        $owner = $this->createAuthenticatedUser(['payment.refunds.view']);
        $this->actingAs($owner);

        $this->intentRepo->method('findById')->willReturn($this->makeIntent($owner->id));
        $this->refundRepo->method('findByIntentId')->willReturn([
            $this->makeRefund(PaymentRefundStatus::Pending, 3000),
        ]);

        $response = $this->getJson("/api/v1/payment/intents/" . self::INTENT_ID . "/refunds");

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.funds_returned', false);
        $response->assertJsonPath('data.0.status_label', 'Being processed');
    }

    public function test_unknown_refund_has_funds_returned_false_and_under_review_label(): void
    {
        $owner = $this->createAuthenticatedUser(['payment.refunds.view']);
        $this->actingAs($owner);

        $this->intentRepo->method('findById')->willReturn($this->makeIntent($owner->id));
        $this->refundRepo->method('findByIntentId')->willReturn([
            $this->makeRefund(PaymentRefundStatus::Unknown, 3000),
        ]);

        $response = $this->getJson("/api/v1/payment/intents/" . self::INTENT_ID . "/refunds");

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.funds_returned', false);
        $response->assertJsonPath('data.0.status_label', 'Under review');
    }

    // ─── (d) non-owner → 403 ────────────────────────────────────────────────

    public function test_non_owner_receives_403(): void
    {
        $nonOwner = $this->createAuthenticatedUser(['payment.refunds.view']);
        $this->actingAs($nonOwner);

        // Intent belongs to a DIFFERENT actor
        $this->intentRepo->method('findById')->willReturn($this->makeIntent('different-actor-id'));

        $response = $this->getJson("/api/v1/payment/intents/" . self::INTENT_ID . "/refunds");

        $response->assertStatus(403);
    }

    // ─── (e) multiple partials → correct meta sums ──────────────────────────

    public function test_meta_sums_are_correct_for_multiple_partials(): void
    {
        $owner = $this->createAuthenticatedUser(['payment.refunds.view']);
        $this->actingAs($owner);

        $this->intentRepo->method('findById')->willReturn($this->makeIntent($owner->id));
        $this->refundRepo->method('findByIntentId')->willReturn([
            $this->makeRefund(PaymentRefundStatus::Confirmed, 2000),
            $this->makeRefund(PaymentRefundStatus::Confirmed, 1500),
            $this->makeRefund(PaymentRefundStatus::Pending, 1000),
        ]);

        $response = $this->getJson("/api/v1/payment/intents/" . self::INTENT_ID . "/refunds");

        $response->assertStatus(200);
        $response->assertJsonPath('meta.total_confirmed_minor', 3500);
        $response->assertJsonPath('meta.remaining_refundable_minor', self::INTENT_AMOUNT - 3500);
        $response->assertJsonPath('meta.has_pending_refunds', true);
    }

    // ─── (f) intent not found → 404 ─────────────────────────────────────────

    public function test_unknown_intent_returns_404(): void
    {
        $owner = $this->createAuthenticatedUser(['payment.refunds.view']);
        $this->actingAs($owner);

        $this->intentRepo->method('findById')->willReturn(null);

        $response = $this->getJson("/api/v1/payment/intents/nonexistent-intent/refunds");

        $response->assertStatus(404);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function makeIntent(string $payerActorId): PaymentIntentModel
    {
        $intent = new PaymentIntentModel();
        $intent->id             = self::INTENT_ID;
        $intent->payer_actor_id = $payerActorId;
        $intent->amount_minor   = self::INTENT_AMOUNT;
        $intent->currency_code  = 'BWP';
        return $intent;
    }

    private function makeRefund(PaymentRefundStatus $status, int $amount): PaymentRefundModel
    {
        $refund = new PaymentRefundModel();
        $refund->id                = 'refund-' . $status->value . '-' . $amount;
        $refund->payment_intent_id = self::INTENT_ID;
        $refund->amount_minor      = $amount;
        $refund->status            = $status;
        $refund->requested_at      = now();
        return $refund;
    }
}
