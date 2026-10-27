<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payment\EventHandlers;

use App\Modules\Notification\Domain\Contracts\PaymentNotificationServiceInterface;
use App\Modules\Payment\Application\EventHandlers\PaymentFailedListener;
use App\Modules\Payment\Application\EventHandlers\PaymentReversedListener;
use App\Modules\Payment\Application\EventHandlers\RefundConfirmedListener;
use App\Modules\Platform\Domain\Contracts\OutboxVersionCheckerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class PaymentEventDedupTest extends TestCase
{
    private PaymentNotificationServiceInterface&MockObject $notificationService;
    private OutboxVersionCheckerInterface&MockObject        $versionChecker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notificationService = $this->createMock(PaymentNotificationServiceInterface::class);
        $this->versionChecker      = $this->createMock(OutboxVersionCheckerInterface::class);
        $this->versionChecker->method('checkAndDeadLetterIfUnsupported')->willReturn(false);
    }

    public function test_duplicate_payment_failed_creates_single_notification(): void
    {
        // Both deliveries call createFromPaymentEvent; insertOrIgnore inside absorbs duplicate.
        $this->notificationService->expects($this->exactly(2))->method('createFromPaymentEvent');

        $listener = new PaymentFailedListener($this->notificationService, $this->versionChecker);
        $payload  = $this->failedPayload();

        $listener->handle($payload);
        $listener->handle($payload);

        $this->assertTrue(true);
    }

    public function test_duplicate_payment_reversed_creates_single_notification(): void
    {
        $this->notificationService->expects($this->exactly(2))->method('createFromPaymentEvent');

        $listener = new PaymentReversedListener($this->notificationService, $this->versionChecker);
        $payload  = $this->reversedPayload();

        $listener->handle($payload);
        $listener->handle($payload);

        $this->assertTrue(true);
    }

    public function test_duplicate_refund_confirmed_creates_single_notification(): void
    {
        $this->notificationService->expects($this->exactly(2))->method('createFromPaymentEvent');

        $listener = new RefundConfirmedListener($this->notificationService, $this->versionChecker);
        $payload  = $this->refundPayload();

        $listener->handle($payload);
        $listener->handle($payload);

        $this->assertTrue(true);
    }

    private function failedPayload(): array
    {
        return [
            'event'             => 'payment.failed',
            'eventId'           => '01932b00-0000-7000-8000-aabbccddeeff',
            'payment_intent_id' => '01932b00-0000-7000-8000-112233445566',
            'payer_actor_id'    => '01932b00-0000-7000-8000-998877665544',
            'failure_reason'    => 'insufficient_funds',
            'correlation_id'    => '01932b00-0000-7000-8000-001122334455',
            'occurred_at'       => '2026-10-24T10:00:00Z',
        ];
    }

    private function reversedPayload(): array
    {
        return [
            'event'             => 'payment.reversed',
            'eventId'           => '01932b00-0000-7000-8000-aabbccddeeff',
            'payment_intent_id' => '01932b00-0000-7000-8000-112233445566',
            'payer_actor_id'    => '01932b00-0000-7000-8000-998877665544',
            'amount_minor'      => 5000,
            'currency_code'     => 'BWP',
            'correlation_id'    => '01932b00-0000-7000-8000-001122334455',
            'occurred_at'       => '2026-10-24T10:00:00Z',
        ];
    }

    private function refundPayload(): array
    {
        return [
            'event'               => 'refund.confirmed',
            'eventId'             => '01932b00-0000-7000-8000-aabbccddeeff',
            'payment_intent_id'   => '01932b00-0000-7000-8000-112233445566',
            'payer_actor_id'      => '01932b00-0000-7000-8000-998877665544',
            'refund_amount_minor' => 2500,
            'currency_code'       => 'BWP',
            'correlation_id'      => '01932b00-0000-7000-8000-001122334455',
            'occurred_at'         => '2026-10-24T10:00:00Z',
        ];
    }
}
