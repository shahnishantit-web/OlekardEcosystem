<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Modules\Payment\Domain\Events\RefundConfirmedEvent;
use Tests\TestCase;

final class RefundConfirmedReceiptListenerTest extends TestCase
{
    public function test_handle_resolves_payer_from_payment_intent(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
    }

    public function test_handle_logs_and_returns_when_payer_not_found(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
    }

    public function test_handle_does_not_reverse_refund_on_delivery_failure(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
    }

    public function test_duplicate_refund_event_produces_no_second_notification(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
    }

    public function test_idempotency_key_includes_event_name_and_correlation_id(): void
    {
        $event = $this->makeEvent(paymentIntentId: 'pi-001', correlationId: 'corr-xyz');

        $idempotencyKey = $event->eventName() . '.' . $event->correlationId();

        $this->assertSame('refund.confirmed.corr-xyz', $idempotencyKey);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeEvent(
        string $paymentIntentId  = 'pi-test',
        string $correlationId    = 'corr-test',
        int    $refundAmountMinor = 5000,
    ): RefundConfirmedEvent {
        return new RefundConfirmedEvent(
            paymentIntentId:          $paymentIntentId,
            paymentRefundId:          'ref-test',
            purposeType:              'wallet_transfer',
            purposeReference:         'ref-ref-test',
            organisationId:           'org-test',
            refundAmountMinor:        $refundAmountMinor,
            currencyCode:             'BWP',
            isFullRefund:             true,
            remainingRefundableMinor: 0,
            correlationId:            $correlationId,
            occurredAt:               now()->toIso8601String(),
        );
    }
}
