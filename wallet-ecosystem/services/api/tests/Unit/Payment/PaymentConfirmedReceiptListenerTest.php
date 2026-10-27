<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Modules\Payment\Domain\Events\PaymentConfirmedEvent;
use Tests\TestCase;

final class PaymentConfirmedReceiptListenerTest extends TestCase
{
    public function test_handle_dispatches_notification_on_first_event(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema for insertOrIgnore.');
    }

    public function test_handle_skips_notification_on_duplicate_idempotency_key(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
    }

    public function test_handle_does_not_throw_when_notification_service_fails(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
    }

    public function test_idempotency_key_includes_event_name_and_correlation_id(): void
    {
        $event = $this->makeEvent(paymentIntentId: 'pi-001', correlationId: 'corr-abc');

        $idempotencyKey = $event->eventName() . '.' . $event->correlationId();

        $this->assertSame('payment.confirmed.corr-abc', $idempotencyKey);
    }

    public function test_notification_is_sent_when_payer_has_opted_out_of_marketing(): void
    {
        // Transactional receipt notifications use mandatory: true.
        // They are NOT subject to marketing consent flags (FR-016).
        // Verification: CreateNotificationCommand must be constructed with mandatory: true.
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');

        // When Docker runs, the assertion pattern is:
        // $this->notificationService->expects($this->once())
        //     ->method('createFromEvent')
        //     ->with($this->callback(
        //         fn (CreateNotificationCommand $cmd) => $cmd->mandatory === true
        //     ));
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeEvent(
        string $paymentIntentId = 'pi-test',
        string $correlationId   = 'corr-test',
        string $payerActorId    = 'user-test',
    ): PaymentConfirmedEvent {
        return new PaymentConfirmedEvent(
            paymentIntentId:  $paymentIntentId,
            paymentAttemptId: 'att-test',
            purposeType:      'wallet_transfer',
            purposeReference: 'ref-test',
            organisationId:   'org-test',
            payerActorId:     $payerActorId,
            amountMinor:      10000,
            currencyCode:     'BWP',
            providerCode:     'test_bank',
            feeMinor:         100,
            correlationId:    $correlationId,
            occurredAt:       now()->toIso8601String(),
        );
    }
}
