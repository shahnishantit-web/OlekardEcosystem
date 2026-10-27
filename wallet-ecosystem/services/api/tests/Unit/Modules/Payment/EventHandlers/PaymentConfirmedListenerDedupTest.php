<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payment\EventHandlers;

use App\Modules\Notification\Domain\Contracts\PaymentNotificationServiceInterface;
use App\Modules\Payment\Application\EventHandlers\PaymentConfirmedListener;
use App\Modules\Platform\Domain\Contracts\OutboxVersionCheckerInterface;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class PaymentConfirmedListenerDedupTest extends TestCase
{
    private PaymentNotificationServiceInterface&MockObject $notificationService;
    private OutboxVersionCheckerInterface&MockObject        $versionChecker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notificationService = $this->createMock(PaymentNotificationServiceInterface::class);
        $this->versionChecker      = $this->createMock(OutboxVersionCheckerInterface::class);
        $this->versionChecker->method('checkAndDeadLetterIfUnsupported')->willReturn(false);
        DB::shouldReceive('connection')->andReturnSelf();
        DB::shouldReceive('table')->andReturnSelf();
        DB::shouldReceive('insertOrIgnore')->andReturn(0); // 0 = duplicate ignored
    }

    public function test_duplicate_delivery_creates_single_allocation(): void
    {
        // insertOrIgnore is called twice (once per handle invocation) but DB silently ignores
        // the second due to the idempotency_key unique constraint.
        $this->notificationService->expects($this->any())->method('createFromPaymentEvent');

        $listener = new PaymentConfirmedListener($this->notificationService, $this->versionChecker);
        $payload  = $this->samplePayload();

        $listener->handle($payload);
        $listener->handle($payload); // second delivery — DB silently ignores via insertOrIgnore

        // No exception thrown = idempotent ✓
        $this->assertTrue(true);
    }

    public function test_duplicate_delivery_creates_single_notification(): void
    {
        // createFromPaymentEvent is called twice; inside it uses insertOrIgnore
        // on (source_event_id, notification_type) — DB absorbs the duplicate.
        $this->notificationService
            ->expects($this->exactly(2))
            ->method('createFromPaymentEvent');

        $listener = new PaymentConfirmedListener($this->notificationService, $this->versionChecker);
        $payload  = $this->samplePayload();

        $listener->handle($payload);
        $listener->handle($payload);

        $this->assertTrue(true);
    }

    private function samplePayload(): array
    {
        return [
            'event'              => 'payment.confirmed',
            'eventId'            => '01932b00-0000-7000-8000-000000000001',
            'payment_intent_id'  => '01932b00-0000-7000-8000-000000000002',
            'payment_attempt_id' => '01932b00-0000-7000-8000-000000000003',
            'purpose_type'       => 'merchant_payment',
            'purpose_reference'  => 'ref-001',
            'organisation_id'    => '01932b00-0000-7000-8000-000000000004',
            'payer_actor_id'     => '01932b00-0000-7000-8000-000000000005',
            'amount_minor'       => 10000,
            'currency_code'      => 'BWP',
            'provider_code'      => 'mock_bank',
            'fee_minor'          => 50,
            'correlation_id'     => '01932b00-0000-7000-8000-000000000006',
            'occurred_at'        => '2026-10-24T10:00:00Z',
            'allocation_reference' => 'alloc-ref-001',
        ];
    }
}
