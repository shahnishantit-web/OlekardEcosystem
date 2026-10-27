<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payment\EventHandlers;

use App\Modules\Notification\Domain\Contracts\PaymentNotificationServiceInterface;
use App\Modules\Payment\Application\EventHandlers\PaymentConfirmedListener;
use App\Modules\Payment\Application\EventHandlers\PaymentFailedListener;
use App\Modules\Payment\Application\EventHandlers\PaymentReversedListener;
use App\Modules\Payment\Application\EventHandlers\RefundConfirmedListener;
use App\Modules\Platform\Domain\Contracts\OutboxVersionCheckerInterface;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class PaymentListenerVersionGuardTest extends TestCase
{
    private PaymentNotificationServiceInterface&MockObject $notificationService;
    private OutboxVersionCheckerInterface&MockObject        $versionChecker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notificationService = $this->createMock(PaymentNotificationServiceInterface::class);
        $this->versionChecker      = $this->createMock(OutboxVersionCheckerInterface::class);
    }

    public function test_payment_confirmed_dead_letters_on_unsupported_version(): void
    {
        $eventId = '01932b00-0000-7000-8000-000000000001';

        $this->versionChecker->expects($this->once())
            ->method('checkAndDeadLetterIfUnsupported')
            ->with($eventId, ['1'])
            ->willReturn(true); // dead-lettered

        $this->notificationService->expects($this->never())->method('createFromPaymentEvent');
        DB::shouldReceive('connection')->never();

        $listener = new PaymentConfirmedListener($this->notificationService, $this->versionChecker);
        $listener->handle(array_merge($this->basePayload(), ['eventId' => $eventId]));
    }

    public function test_payment_failed_dead_letters_on_unsupported_version(): void
    {
        $eventId = '01932b00-0000-7000-8000-000000000002';

        $this->versionChecker->expects($this->once())
            ->method('checkAndDeadLetterIfUnsupported')
            ->with($eventId, ['1'])
            ->willReturn(true);

        $this->notificationService->expects($this->never())->method('createFromPaymentEvent');

        $listener = new PaymentFailedListener($this->notificationService, $this->versionChecker);
        $listener->handle(array_merge($this->basePayload(), ['eventId' => $eventId]));
    }

    public function test_payment_reversed_dead_letters_on_unsupported_version(): void
    {
        $eventId = '01932b00-0000-7000-8000-000000000003';

        $this->versionChecker->expects($this->once())
            ->method('checkAndDeadLetterIfUnsupported')
            ->with($eventId, ['1'])
            ->willReturn(true);

        $this->notificationService->expects($this->never())->method('createFromPaymentEvent');

        $listener = new PaymentReversedListener($this->notificationService, $this->versionChecker);
        $listener->handle(array_merge($this->basePayload(), ['eventId' => $eventId]));
    }

    public function test_refund_confirmed_dead_letters_on_unsupported_version(): void
    {
        $eventId = '01932b00-0000-7000-8000-000000000004';

        $this->versionChecker->expects($this->once())
            ->method('checkAndDeadLetterIfUnsupported')
            ->with($eventId, ['1'])
            ->willReturn(true);

        $this->notificationService->expects($this->never())->method('createFromPaymentEvent');

        $listener = new RefundConfirmedListener($this->notificationService, $this->versionChecker);
        $listener->handle(array_merge($this->basePayload(), ['eventId' => $eventId]));
    }

    private function basePayload(): array
    {
        return [
            'event'             => 'payment.confirmed',
            'payment_intent_id' => '01932b00-0000-7000-8000-aabbccddeeff',
            'payer_actor_id'    => '01932b00-0000-7000-8000-112233445566',
            'correlation_id'    => '01932b00-0000-7000-8000-998877665544',
            'amount_minor'      => 5000,
            'currency_code'     => 'BWP',
            'occurred_at'       => '2026-10-24T10:00:00Z',
        ];
    }
}
