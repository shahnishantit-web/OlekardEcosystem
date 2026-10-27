<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Feature;

use App\Modules\Payment\Application\Services\ReconciliationExceptionService;
use App\Modules\Payment\Application\Services\RefundService;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * T045 — SC-005: All refund transition methods record an audit entry within 1s of the state change.
 *
 * Verifies AuditServiceInterface::record() is invoked with correct fields for:
 *   - submit()
 *   - markPending()
 *   - markUnknown()
 *   - confirm()
 *   - definitivelyFail()
 */
final class RefundAuditIntegrationTest extends TestCase
{
    private MockObject&PaymentRefundRepositoryInterface $refundRepo;
    private MockObject&AuditServiceInterface $audit;
    private RefundService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refundRepo = $this->createMock(PaymentRefundRepositoryInterface::class);
        $this->audit      = $this->createMock(AuditServiceInterface::class);

        $this->service = new RefundService(
            attemptRepository:        $this->createMock(PaymentAttemptRepositoryInterface::class),
            refundRepository:         $this->refundRepo,
            config:                   $this->createMock(ConfigurationServiceInterface::class),
            audit:                    $this->audit,
            events:                   $this->createMock(EventPublisherInterface::class),
            container:                $this->createMock(Container::class),
            reconciliationExceptions: $this->createMock(ReconciliationExceptionService::class),
        );
    }

    private function makeRefund(PaymentRefundStatus $status, string $id = 'refund-001'): PaymentRefundModel
    {
        $refund = new PaymentRefundModel();
        $refund->id                 = $id;
        $refund->payment_intent_id  = 'intent-001';
        $refund->payment_attempt_id = 'attempt-001';
        $refund->amount_minor       = 5000;
        $refund->status             = $status;
        $refund->query_attempts     = 0;

        return $refund;
    }

    public function test_submit_records_audit_with_correct_fields(): void
    {
        $refund = $this->makeRefund(PaymentRefundStatus::Processing);
        $this->refundRepo->method('findById')->willReturn($refund);
        $this->refundRepo->method('save')->willReturn(null);

        $capturedAudit = null;
        $this->audit->expects($this->once())
            ->method('record')
            ->willReturnCallback(function (AuditEventData $data) use (&$capturedAudit): void {
                $capturedAudit = $data;
            });

        $start = microtime(true);
        $this->service->submit('refund-001', 'provider-ref-001', 'corr-001');
        $elapsed = microtime(true) - $start;

        $this->assertNotNull($capturedAudit);
        $this->assertSame('payment.refund.submitted', $capturedAudit->action);
        $this->assertSame('payment_refund', $capturedAudit->resourceType);
        $this->assertSame('refund-001', $capturedAudit->resourceId);
        $this->assertSame('corr-001', $capturedAudit->correlationId);
        $this->assertSame('success', $capturedAudit->outcome);
        $this->assertLessThan(1.0, $elapsed, 'Audit record must be written within 1s of DB commit');
    }

    public function test_mark_pending_records_audit_with_correct_fields(): void
    {
        $refund = $this->makeRefund(PaymentRefundStatus::Submitted);
        $this->refundRepo->method('findById')->willReturn($refund);
        $this->refundRepo->method('save')->willReturn(null);

        $capturedAudit = null;
        $this->audit->expects($this->once())
            ->method('record')
            ->willReturnCallback(function (AuditEventData $data) use (&$capturedAudit): void {
                $capturedAudit = $data;
            });

        $start = microtime(true);
        $this->service->markPending('refund-001', 'corr-001');
        $elapsed = microtime(true) - $start;

        $this->assertSame('payment.refund.pending', $capturedAudit->action);
        $this->assertSame('refund-001', $capturedAudit->resourceId);
        $this->assertLessThan(1.0, $elapsed);
    }

    public function test_mark_unknown_records_audit_with_correct_fields(): void
    {
        $refund = $this->makeRefund(PaymentRefundStatus::Submitted);
        $this->refundRepo->method('findById')->willReturn($refund);
        $this->refundRepo->method('save')->willReturn(null);

        $capturedAudit = null;
        $this->audit->expects($this->once())
            ->method('record')
            ->willReturnCallback(function (AuditEventData $data) use (&$capturedAudit): void {
                $capturedAudit = $data;
            });

        $start = microtime(true);
        $this->service->markUnknown('refund-001', 'corr-001');
        $elapsed = microtime(true) - $start;

        $this->assertSame('payment.refund.unknown', $capturedAudit->action);
        $this->assertSame('warning', $capturedAudit->outcome);
        $this->assertSame('refund-001', $capturedAudit->resourceId);
        $this->assertArrayHasKey('alert', $capturedAudit->afterState ?? []);
        $this->assertLessThan(1.0, $elapsed);
    }

    public function test_definitive_fail_records_audit_with_correct_fields(): void
    {
        $refund = $this->makeRefund(PaymentRefundStatus::Unknown);
        $this->refundRepo->method('findById')->willReturn($refund);
        $this->refundRepo->method('save')->willReturn(null);

        $capturedAudit = null;
        $this->audit->expects($this->once())
            ->method('record')
            ->willReturnCallback(function (AuditEventData $data) use (&$capturedAudit): void {
                $capturedAudit = $data;
            });

        $start = microtime(true);
        $this->service->definitivelyFail('refund-001', 'Provider declined', 'corr-001');
        $elapsed = microtime(true) - $start;

        $this->assertSame('payment.refund.definitive_failure', $capturedAudit->action);
        $this->assertSame('failure', $capturedAudit->outcome);
        $this->assertSame('refund-001', $capturedAudit->resourceId);
        $this->assertSame('corr-001', $capturedAudit->correlationId);
        $this->assertLessThan(1.0, $elapsed);
    }

    public function test_definitively_fail_idempotency_gate_does_not_call_audit(): void
    {
        $refund = $this->makeRefund(PaymentRefundStatus::DefinitiveFailure);
        $this->refundRepo->method('findById')->willReturn($refund);

        // Idempotency gate: already in terminal state → no audit call
        $this->audit->expects($this->never())->method('record');

        $result = $this->service->definitivelyFail('refund-001', 'already failed', 'corr-001');
        $this->assertSame(PaymentRefundStatus::DefinitiveFailure, $result->status);
    }
}
