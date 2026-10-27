<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payment\EventHandlers;

use App\Modules\Notification\Application\Commands\CreatePaymentNotificationCommand;
use App\Modules\Notification\Domain\Contracts\PaymentNotificationServiceInterface;
use App\Modules\Payment\Application\EventHandlers\PaymentConfirmedListener;
use App\Modules\Payment\Application\EventHandlers\PaymentFailedListener;
use App\Modules\Payment\Application\EventHandlers\PaymentReversedListener;
use App\Modules\Payment\Application\EventHandlers\RefundConfirmedListener;
use App\Modules\Platform\Domain\Contracts\OutboxVersionCheckerInterface;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class PaymentListenerRoutingTest extends TestCase
{
    private PaymentNotificationServiceInterface&MockObject $notificationService;
    private OutboxVersionCheckerInterface&MockObject        $versionChecker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notificationService = $this->createMock(PaymentNotificationServiceInterface::class);
        $this->versionChecker      = $this->createMock(OutboxVersionCheckerInterface::class);
        // Version checker always passes (returns false = not dead-lettered) in routing tests.
        $this->versionChecker->method('checkAndDeadLetterIfUnsupported')->willReturn(false);
        DB::shouldReceive('connection')->andReturnSelf();
        DB::shouldReceive('table')->andReturnSelf();
        DB::shouldReceive('insertOrIgnore')->andReturn(true);
    }

    public function test_payment_confirmed_listener_calls_notification_service(): void
    {
        $this->notificationService
            ->expects($this->once())
            ->method('createFromPaymentEvent')
            ->with($this->callback(static fn (CreatePaymentNotificationCommand $cmd) =>
                $cmd->notificationType === 'payment_confirmed'
            ));

        $listener = new PaymentConfirmedListener($this->notificationService, $this->versionChecker);
        $listener->handle($this->confirmedPayload());
    }

    public function test_payment_failed_listener_calls_notification_service(): void
    {
        $this->notificationService
            ->expects($this->once())
            ->method('createFromPaymentEvent')
            ->with($this->callback(static fn (CreatePaymentNotificationCommand $cmd) =>
                $cmd->notificationType === 'payment_failed'
            ));

        $listener = new PaymentFailedListener($this->notificationService, $this->versionChecker);
        $listener->handle($this->failedPayload());
    }

    public function test_payment_reversed_listener_calls_notification_service(): void
    {
        $this->notificationService
            ->expects($this->once())
            ->method('createFromPaymentEvent')
            ->with($this->callback(static fn (CreatePaymentNotificationCommand $cmd) =>
                $cmd->notificationType === 'payment_reversed'
            ));

        $listener = new PaymentReversedListener($this->notificationService, $this->versionChecker);
        $listener->handle($this->reversedPayload());
    }

    public function test_refund_confirmed_listener_calls_notification_service(): void
    {
        $this->notificationService
            ->expects($this->once())
            ->method('createFromPaymentEvent')
            ->with($this->callback(static fn (CreatePaymentNotificationCommand $cmd) =>
                $cmd->notificationType === 'refund_confirmed'
            ));

        $listener = new RefundConfirmedListener($this->notificationService, $this->versionChecker);
        $listener->handle($this->refundPayload());
    }

    private function confirmedPayload(): array
    {
        return [
            'event'              => 'payment.confirmed',
            'payment_intent_id'  => '01932b00-0000-7000-8000-000000000001',
            'payment_attempt_id' => '01932b00-0000-7000-8000-000000000002',
            'purpose_type'       => 'merchant_payment',
            'purpose_reference'  => 'ref-001',
            'organisation_id'    => '01932b00-0000-7000-8000-000000000003',
            'payer_actor_id'     => '01932b00-0000-7000-8000-000000000004',
            'amount_minor'       => 10000,
            'currency_code'      => 'BWP',
            'provider_code'      => 'mock_bank',
            'fee_minor'          => 50,
            'correlation_id'     => '01932b00-0000-7000-8000-000000000005',
            'occurred_at'        => '2026-10-24T10:00:00Z',
            'allocation_reference' => 'alloc-001',
        ];
    }

    private function failedPayload(): array
    {
        return [
            'event'             => 'payment.failed',
            'payment_intent_id' => '01932b00-0000-7000-8000-000000000001',
            'purpose_type'      => 'merchant_payment',
            'purpose_reference' => 'ref-001',
            'organisation_id'   => '01932b00-0000-7000-8000-000000000003',
            'payer_actor_id'    => '01932b00-0000-7000-8000-000000000004',
            'failure_reason'    => 'insufficient_funds',
            'correlation_id'    => '01932b00-0000-7000-8000-000000000005',
            'occurred_at'       => '2026-10-24T10:00:00Z',
        ];
    }

    private function reversedPayload(): array
    {
        return [
            'event'              => 'payment.reversed',
            'payment_intent_id'  => '01932b00-0000-7000-8000-000000000001',
            'payment_attempt_id' => '01932b00-0000-7000-8000-000000000002',
            'purpose_type'       => 'merchant_payment',
            'purpose_reference'  => 'ref-001',
            'organisation_id'    => '01932b00-0000-7000-8000-000000000003',
            'payer_actor_id'     => '01932b00-0000-7000-8000-000000000004',
            'amount_minor'       => 10000,
            'currency_code'      => 'BWP',
            'correlation_id'     => '01932b00-0000-7000-8000-000000000005',
            'occurred_at'        => '2026-10-24T10:00:00Z',
        ];
    }

    private function refundPayload(): array
    {
        return [
            'event'                      => 'refund.confirmed',
            'payment_intent_id'          => '01932b00-0000-7000-8000-000000000001',
            'payment_refund_id'          => '01932b00-0000-7000-8000-000000000006',
            'purpose_type'               => 'merchant_payment',
            'purpose_reference'          => 'ref-001',
            'organisation_id'            => '01932b00-0000-7000-8000-000000000003',
            'payer_actor_id'             => '01932b00-0000-7000-8000-000000000004',
            'refund_amount_minor'        => 5000,
            'currency_code'              => 'BWP',
            'is_full_refund'             => false,
            'remaining_refundable_minor' => 5000,
            'correlation_id'             => '01932b00-0000-7000-8000-000000000005',
            'occurred_at'                => '2026-10-24T10:00:00Z',
        ];
    }
}
