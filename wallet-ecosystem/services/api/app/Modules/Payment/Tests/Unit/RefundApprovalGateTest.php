<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Application\Services\ReconciliationExceptionService;
use App\Modules\Payment\Application\Services\RefundService;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Domain\Exceptions\InvalidStateTransitionException;
use App\Modules\Payment\Domain\Exceptions\SelfApprovalNotAllowedException;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class RefundApprovalGateTest extends TestCase
{
    private MockObject&PaymentRefundRepositoryInterface $refundRepo;
    private MockObject&PaymentAttemptRepositoryInterface $attemptRepo;
    private MockObject&ConfigurationServiceInterface $config;
    private MockObject&AuditServiceInterface $audit;
    private RefundService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refundRepo  = $this->createMock(PaymentRefundRepositoryInterface::class);
        $this->attemptRepo = $this->createMock(PaymentAttemptRepositoryInterface::class);
        $this->config      = $this->createMock(ConfigurationServiceInterface::class);
        $this->audit       = $this->createMock(AuditServiceInterface::class);

        $this->service = new RefundService(
            attemptRepository:       $this->attemptRepo,
            refundRepository:        $this->refundRepo,
            config:                  $this->config,
            audit:                   $this->audit,
            events:                  $this->createMock(EventPublisherInterface::class),
            container:               $this->createMock(Container::class),
            reconciliationExceptions: $this->createMock(ReconciliationExceptionService::class),
        );
    }

    public function test_self_approval_throws_self_approval_not_allowed_exception(): void
    {
        $refundId = 'refund-uuid-001';
        $actorId  = 'actor-a';

        $refund               = new PaymentRefundModel();
        $refund->requested_by = $actorId;
        $refund->status       = PaymentRefundStatus::PendingApproval;

        $this->refundRepo->method('findById')->with($refundId)->willReturn($refund);

        $this->expectException(SelfApprovalNotAllowedException::class);

        $this->service->approveRefund($refundId, $actorId, 'corr-001');
    }

    public function test_approval_by_different_actor_transitions_to_processing(): void
    {
        $refundId   = 'refund-uuid-001';
        $requesterId = 'actor-a';
        $approverId  = 'actor-b';

        $refund               = new PaymentRefundModel();
        $refund->requested_by = $requesterId;
        $refund->status       = PaymentRefundStatus::PendingApproval;

        $this->refundRepo->method('findById')->with($refundId)->willReturn($refund);
        $this->refundRepo->expects($this->once())->method('save');
        $this->audit->expects($this->once())->method('record');

        $result = $this->service->approveRefund($refundId, $approverId, 'corr-001');

        $this->assertSame(PaymentRefundStatus::Processing, $result->status);
        $this->assertSame($approverId, $result->approved_by_actor_id);
    }

    public function test_rejection_by_different_actor_transitions_to_rejected(): void
    {
        $refundId    = 'refund-uuid-001';
        $requesterId = 'actor-a';
        $reviewerId  = 'actor-b';

        $refund               = new PaymentRefundModel();
        $refund->requested_by = $requesterId;
        $refund->status       = PaymentRefundStatus::PendingApproval;

        $this->refundRepo->method('findById')->with($refundId)->willReturn($refund);
        $this->refundRepo->expects($this->once())->method('save');

        $result = $this->service->rejectRefund($refundId, $reviewerId, 'duplicate request', 'corr-001');

        $this->assertSame(PaymentRefundStatus::Rejected, $result->status);
        $this->assertSame($reviewerId, $result->rejected_by_actor_id);
        $this->assertSame('duplicate request', $result->rejection_reason);
    }

    public function test_double_approve_of_already_approved_refund_throws(): void
    {
        $refundId   = 'refund-uuid-001';
        $approverId = 'actor-b';

        $refund               = new PaymentRefundModel();
        $refund->requested_by = 'actor-a';
        $refund->status       = PaymentRefundStatus::Processing; // already transitioned

        $this->refundRepo->method('findById')->willReturn($refund);

        $this->expectException(InvalidStateTransitionException::class);

        $this->service->approveRefund($refundId, $approverId, 'corr-001');
    }

    public function test_self_rejection_throws(): void
    {
        $refundId = 'refund-uuid-001';
        $actorId  = 'actor-a';

        $refund               = new PaymentRefundModel();
        $refund->requested_by = $actorId;
        $refund->status       = PaymentRefundStatus::PendingApproval;

        $this->refundRepo->method('findById')->willReturn($refund);

        $this->expectException(SelfApprovalNotAllowedException::class);

        $this->service->rejectRefund($refundId, $actorId, 'some reason', 'corr-001');
    }
}
