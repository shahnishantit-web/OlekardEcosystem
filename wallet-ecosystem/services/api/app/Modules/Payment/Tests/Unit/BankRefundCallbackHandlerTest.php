<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Application\EventHandlers\BankRefundCallbackHandler;
use App\Modules\Payment\Application\Services\RefundService;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * T023 — BankRefundCallbackHandler processes bank refund callbacks idempotently.
 *
 *  (a) Confirmed callback advances pending → confirmed via RefundService::confirm()
 *  (b) Declined callback advances pending → definitive_failure via RefundService::definitivelyFail()
 *  (c) Duplicate confirmed callback is a no-op (terminal status gate)
 *  (d) Callback for unknown providerSubmittedRef is logged and rejected
 */
final class BankRefundCallbackHandlerTest extends TestCase
{
    private MockObject&PaymentRefundRepositoryInterface $refundRepo;
    private MockObject&RefundService $refundService;
    private BankRefundCallbackHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refundRepo    = $this->createMock(PaymentRefundRepositoryInterface::class);
        $this->refundService = $this->createMock(RefundService::class);
        $this->handler       = new BankRefundCallbackHandler($this->refundRepo, $this->refundService);
    }

    private function makeRefund(PaymentRefundStatus $status): PaymentRefundModel
    {
        $refund = new PaymentRefundModel();
        $refund->id                  = 'refund-001';
        $refund->payment_intent_id   = 'intent-001';
        $refund->payment_attempt_id  = 'attempt-001';
        $refund->amount_minor        = 5000;
        $refund->status              = $status;
        $refund->provider_submitted_ref = 'bank-ref-001';
        return $refund;
    }

    // ─── (a) confirmed callback advances to confirmed ─────────────────────────

    public function test_confirmed_callback_calls_confirm_on_pending_refund(): void
    {
        $refund = $this->makeRefund(PaymentRefundStatus::Pending);
        $this->refundRepo->method('findByProviderSubmittedRef')
            ->with('bank-ref-001')
            ->willReturn($refund);

        $this->refundService->expects($this->once())
            ->method('confirm')
            ->with('refund-001', 'bank-ref-001', $this->anything());

        $this->handler->handle([
            'event'                  => 'bank.refund.callback',
            'provider_submitted_ref' => 'bank-ref-001',
            'status'                 => 'confirmed',
            'correlation_id'         => 'corr-001',
        ]);
    }

    // ─── (b) declined callback advances to definitive_failure ────────────────

    public function test_declined_callback_calls_definitive_fail(): void
    {
        $refund = $this->makeRefund(PaymentRefundStatus::Pending);
        $this->refundRepo->method('findByProviderSubmittedRef')->willReturn($refund);

        $this->refundService->expects($this->once())
            ->method('definitivelyFail')
            ->with('refund-001', $this->anything(), $this->anything());

        $this->handler->handle([
            'event'                  => 'bank.refund.callback',
            'provider_submitted_ref' => 'bank-ref-001',
            'status'                 => 'declined',
            'decline_reason'         => 'Insufficient funds at bank',
            'correlation_id'         => 'corr-001',
        ]);
    }

    // ─── (c) duplicate confirmed callback is a no-op ─────────────────────────

    public function test_duplicate_confirmed_callback_is_noop(): void
    {
        $refund = $this->makeRefund(PaymentRefundStatus::Confirmed);
        $this->refundRepo->method('findByProviderSubmittedRef')->willReturn($refund);

        $this->refundService->expects($this->never())->method('confirm');
        $this->refundService->expects($this->never())->method('definitivelyFail');

        $this->handler->handle([
            'event'                  => 'bank.refund.callback',
            'provider_submitted_ref' => 'bank-ref-001',
            'status'                 => 'confirmed',
            'correlation_id'         => 'corr-001',
        ]);
    }

    // ─── (d) unknown providerSubmittedRef → logged, no service call ──────────

    public function test_unknown_provider_ref_is_rejected_gracefully(): void
    {
        $this->refundRepo->method('findByProviderSubmittedRef')->willReturn(null);

        $this->refundService->expects($this->never())->method('confirm');
        $this->refundService->expects($this->never())->method('definitivelyFail');

        $result = $this->handler->handle([
            'event'                  => 'bank.refund.callback',
            'provider_submitted_ref' => 'unknown-ref-999',
            'status'                 => 'confirmed',
            'correlation_id'         => 'corr-001',
        ]);

        $this->assertFalse($result, 'Handler should return false (ack but reject) for unknown ref');
    }
}
